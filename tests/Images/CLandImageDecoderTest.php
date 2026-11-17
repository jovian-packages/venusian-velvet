<?php

declare(strict_types=1);

use Jovian\Engines\Velvet\Lustrous\Framebuffers\CLandFramebufferDriver;
use Jovian\Engines\Velvet\Lustrous\Images\CLandImageDecoder;
use Jovian\Engines\Velvet\Providers\VenusianVelvetServiceProvider;
use Surface\Contracts\Images\ImageException;
use Surface\Contracts\Images\ImageFormat;
use Surface\Contracts\Images\ImageSource;
use Surface\Contracts\Images\StoreImageDecoder;
use Surface\Contracts\Rendering\Framebuffers\BitDepth;
use Surface\Contracts\Rendering\Framebuffers\ChannelOrder;
use Surface\Contracts\Rendering\Framebuffers\FormatSpec;
use Surface\Contracts\Rendering\Framebuffers\PixelFormat;
use Surface\Contracts\Rendering\Framebuffers\PixelMemory;
use Surface\Contracts\Rendering\Framebuffers\ScanDirection;

/*
 * lustrous-c writes ext-imgdec's pixels into a C frame's own memory. The expected colours are the
 * file's: gd's alpha 0 is opaque, so a pixel stored as (255, 0, 0, 0) in gd is ff0000ff.
 */

function clandPng(int $width, int $height, Closure $pixel): string
{
    $image = imagecreatetruecolor($width, $height);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    for ($y = 0; $y < $height; $y++) {
        for ($x = 0; $x < $width; $x++) {
            [$r, $g, $b, $a] = $pixel($x, $y);
            imagesetpixel($image, $x, $y, ($a << 24) | ($r << 16) | ($g << 8) | $b);
        }
    }
    ob_start();
    imagepng($image);

    return ob_get_clean();
}

/** A little-endian uncompressed 1×1 RGB TIFF, or a header that only claims a size. */
function clandTiff(int $width, int $height, string $samples): string
{
    $tags = [256 => [$width], 257 => [$height], 258 => [8, 8, 8], 259 => [1], 262 => [2], 273 => [8], 277 => [3], 278 => [$height], 279 => [strlen($samples)]];
    $ifd = 8 + strlen($samples) + (strlen($samples) % 2);
    $entries = '';
    $values = '';
    $after = $ifd + 2 + 12 * count($tags) + 4;
    foreach ($tags as $tag => $list) {
        $type = in_array($tag, [256, 257, 273, 278, 279], true) ? 4 : 3;
        $packed = pack($type === 4 ? 'V*' : 'v*', ...$list);
        $entries .= pack('vvV', $tag, $type, count($list)).(strlen($packed) <= 4 ? str_pad($packed, 4, "\0") : pack('V', $after + strlen($values)));
        if (strlen($packed) > 4) {
            $values .= $packed;
        }
    }

    return "II*\0".pack('V', $ifd).$samples.(strlen($samples) % 2 ? "\0" : '').pack('v', count($tags)).$entries.pack('V', 0).$values;
}

function clandDecoder(): CLandImageDecoder
{
    function_exists('imgdec_decode_into') || throw new RuntimeException('ext-imgdec cannot write a frame at an address in this PHP.');

    return new CLandImageDecoder();
}

it('registers lustrous-c only where ext-imgdec writes an address', function () {
    $names = array_keys(VenusianVelvetServiceProvider::imageDecoders());

    expect($names)->toBe(function_exists('imgdec_decode_into') ? ['lustrous-c', 'lustrous-php'] : ['lustrous-php']);
    if ($names === []) {
        return;
    }
    $decoder = clandDecoder();
    expect($decoder)->not->toBeInstanceOf(StoreImageDecoder::class)
        ->and($decoder->driver())->toBe('lustrous-c')
        ->and($decoder->writes(new FormatSpec(PixelFormat::ROW_MAJOR, BitDepth::B32, ScanDirection::BOTTOM_TO_TOP, channel_order: ChannelOrder::RGBA)))->toBeFalse();
});

it('writes a PNG into a C frame in both channel orders, and leaves a wider stride\'s padding untouched', function () {
    $png = clandPng(2, 1, fn (int $x) => $x === 0 ? [255, 0, 0, 0] : [0, 255, 0, 0]);
    $decoder = clandDecoder();

    foreach ([FormatSpec::rgba8(), FormatSpec::bgra8()] as $format) {
        $frame = (new CLandFramebufferDriver())->dirty($format, 2, 1);
        $region = $decoder->decodeInto(ImageSource::containing($png), $frame->memory());

        expect($region->x)->toBe(0)
            ->and($region->y)->toBe(0)
            ->and($region->width)->toBe(2)
            ->and($region->height)->toBe(1)
            ->and($frame->getPixel(0, 0))->toBe(0xFF0000FF)
            ->and($frame->getPixel(1, 0))->toBe(0x00FF00FF);
    }

    $tall = clandPng(2, 2, fn (int $x, int $y) => match ($y) {
        0 => $x === 0 ? [255, 0, 0, 0] : [0, 255, 0, 0],
        default => [0, 0, 255, 0],
    });
    $stride = 2 * 4 + 8;
    $owner = new FbBuffer(new FbFormat(FB_LAYOUT_INDEX8), $stride, 2);
    $owner->rect(0, 0, $stride, 2, 0xA5);
    $memory = new PixelMemory($owner->pointer(), 2, 2, $stride, FormatSpec::rgba8(), $owner);
    $decoder->decodeInto(ImageSource::containing($tall), $memory);
    $view = FbBuffer::over(new FbFormat(FB_LAYOUT_RGBA8888, channelOrder: FB_CHANNELS_RGBA), 2, 2, $owner->pointer(), $owner, $stride);

    expect($view->get(0, 0))->toBe(0xFF0000FF)
        ->and($view->get(1, 0))->toBe(0x00FF00FF)
        ->and($view->get(0, 1))->toBe(0x0000FFFF);
    for ($y = 0; $y < 2; $y++) {
        for ($x = 8; $x < $stride; $x++) {
            expect($owner->get($x, $y))->toBe(0xA5);
        }
    }
});

it('refuses a target shorter than the image before writing a byte', function () {
    $png = clandPng(2, 2, fn () => [1, 2, 3, 0]);
    $stride = 8;
    $owner = new FbBuffer(new FbFormat(FB_LAYOUT_INDEX8), $stride, 1);
    $owner->rect(0, 0, $stride, 1, 0xA5);
    $before = $owner->region(0, 0, $stride, 1);
    $memory = new PixelMemory($owner->pointer(), 2, 1, $stride, FormatSpec::rgba8(), $owner);

    expect(fn () => clandDecoder()->decodeInto(ImageSource::containing($png), $memory))->toThrow(ImageException::class, 'capacity');
    expect($owner->region(0, 0, $stride, 1))->toBe($before);
});

it('reads a header that ends after IHDR, and names a missing frame and a size past the limit', function () {
    $png = clandPng(3, 5, fn () => [1, 2, 3, 0]);
    $info = clandDecoder()->info(ImageSource::containing(substr($png, 0, 33)));
    $tiff = clandTiff(1, 1, "\x10\x20\x30");

    expect($info->format)->toBe(ImageFormat::PNG)
        ->and($info->width)->toBe(3)
        ->and($info->height)->toBe(5)
        ->and(fn () => clandDecoder()->decodeInto(ImageSource::containing($tiff), (new CLandFramebufferDriver())->dirty(FormatSpec::rgba8(), 1, 1)->memory(), 1))->toThrow(function (ImageException $e) {
            expect($e->getMessage())->toBe('TIFF image has no frame 1; it has 1.');
        })
        ->and(fn () => clandDecoder()->info(ImageSource::containing(clandTiff(9000, 9000, "\0"))))->toThrow(function (ImageException $e) {
            expect($e->getMessage())->toBe('TIFF image is 9000x9000, past the limit.');
        });
});
