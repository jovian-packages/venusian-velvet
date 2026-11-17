<?php

declare(strict_types=1);

use Jovian\Engines\Velvet\Lustrous\Framebuffers\CLandFramebufferDriver;
use Jovian\Engines\Velvet\Lustrous\Framebuffers\PHPLandFramebufferDriver;
use Jovian\Engines\Velvet\Lustrous\Images\PHPLandImageDecoder;
use Jovian\Engines\Velvet\Providers\VenusianVelvetServiceProvider;
use Surface\Contracts\Images\ImageDecoder;
use Surface\Contracts\Images\ImageException;
use Surface\Contracts\Images\ImageFormat;
use Surface\Contracts\Images\ImageSource;
use Surface\Contracts\Rendering\Framebuffers\FormatSpec;
use Surface\Contracts\Rendering\Framebuffers\ScanDirection;

/*
 * lustrous-php writes through the PHP store. gd's alpha is 7 bits, 0 opaque and 127 clear, widened
 * as 255 − ((a << 1) + (a >> 6)): 0 → 255, 1 → 253, 64 → 126, 127 → 0. The numbers below are that
 * formula applied to the pixels this test stored. They are not a comparison with another decoder.
 */

function phplandPng(int $width, int $height, Closure $pixel): string
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

/** @param array<int, list<int>> $extra */
function phplandTiff(int $width, int $height, int $photometric, int $bits, int $spp, string $samples, array $extra = []): string
{
    $tags = [256 => [$width], 257 => [$height], 258 => array_fill(0, $spp, $bits), 259 => [1], 262 => [$photometric], 273 => [8], 277 => [$spp], 278 => [$height], 279 => [strlen($samples)]] + $extra;
    ksort($tags);
    $ifd = 8 + strlen($samples) + (strlen($samples) % 2);
    $values = '';
    $entries = '';
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

it('registers lustrous-php after lustrous-c, and it is not an address decoder', function () {
    $names = array_keys(VenusianVelvetServiceProvider::imageDecoders());

    expect($names[array_key_last($names)])->toBe('lustrous-php')
        ->and(new PHPLandImageDecoder())->not->toBeInstanceOf(ImageDecoder::class)
        ->and((new PHPLandImageDecoder())->driver())->toBe('lustrous-php');
});

it('writes gd\'s widened alpha into the PHP store, and a bottom-to-top frame keeps the first row at the top', function (ScanDirection $scan) {
    $png = phplandPng(4, 1, fn (int $x) => [$x * 60, 0, 7, [0, 1, 64, 127][$x]]);
    $format = new FormatSpec(
        \Surface\Contracts\Rendering\Framebuffers\PixelFormat::ROW_MAJOR,
        \Surface\Contracts\Rendering\Framebuffers\BitDepth::B32,
        $scan,
        channel_order: \Surface\Contracts\Rendering\Framebuffers\ChannelOrder::RGBA,
    );
    $frame = (new PHPLandFramebufferDriver())->dirty($format, 4, 1);

    (new PHPLandImageDecoder())->decodeIntoStore(ImageSource::containing($png), $frame);

    expect($frame->getPixel(0, 0))->toBe(0x000007FF)
        ->and($frame->getPixel(1, 0))->toBe(0x3C0007FD)
        ->and($frame->getPixel(2, 0))->toBe(0x7800077E)
        ->and($frame->getPixel(3, 0))->toBe(0xB4000700);
})->with([
    'top to bottom' => [ScanDirection::TOP_TO_BOTTOM],
    'bottom to top' => [ScanDirection::BOTTOM_TO_TOP],
]);

it('writes a TIFF\'s own samples, and divides associated alpha out', function () {
    $rgb = phplandTiff(2, 1, 2, 8, 3, "\x10\x20\x30\x40\x50\x60");
    $associated = phplandTiff(1, 1, 1, 8, 2, "\x40\x80", [338 => [1]]);
    $frames = new PHPLandFramebufferDriver();
    $rgbFrame = $frames->dirty(FormatSpec::rgba8(), 2, 1);
    $greyFrame = $frames->dirty(FormatSpec::rgba8(), 1, 1);
    $decoder = new PHPLandImageDecoder();
    $decoder->decodeIntoStore(ImageSource::containing($rgb), $rgbFrame);
    $decoder->decodeIntoStore(ImageSource::containing($associated), $greyFrame);

    // 0x40 was stored already multiplied by alpha 0x80. Straight is round(0x40 × 255 ÷ 128) = 128.
    expect($rgbFrame->getPixel(0, 0))->toBe(0x102030FF)
        ->and($rgbFrame->getPixel(1, 0))->toBe(0x405060FF)
        ->and($greyFrame->getPixel(0, 0))->toBe(0x80808080);
});

it('refuses a frame that has memory, and a frame of the wrong size, before either is written', function () {
    $png = phplandPng(2, 2, fn () => [255, 0, 0, 0]);
    $c = (new CLandFramebufferDriver())->dirty(FormatSpec::rgba8(), 2, 2);
    $small = (new PHPLandFramebufferDriver())->dirty(FormatSpec::rgba8(), 1, 1);
    $decoder = new PHPLandImageDecoder();

    expect(fn () => $decoder->decodeIntoStore(ImageSource::containing($png), $c))->toThrow(ImageException::class, 'lustrous-php writes through the PHP store, and this frame has its own memory.')
        ->and($c->getPixel(0, 0))->toBe(0)
        ->and(fn () => $decoder->decodeIntoStore(ImageSource::containing($png), $small))->toThrow(ImageException::class, 'it is 2x2, and the frame is 1x1.')
        ->and($small->getPixel(0, 0))->toBe(0);
});

it('reads a PNG header that ends after IHDR, and names a TIFF frame the file does not have', function () {
    $png = phplandPng(3, 5, fn () => [1, 2, 3, 0]);
    $info = (new PHPLandImageDecoder())->info(ImageSource::containing(substr($png, 0, 33)));
    $tiff = phplandTiff(1, 1, 2, 8, 3, "\x10\x20\x30");

    expect($info->format)->toBe(ImageFormat::PNG)
        ->and($info->width)->toBe(3)
        ->and($info->height)->toBe(5)
        ->and(fn () => (new PHPLandImageDecoder())->decodeIntoStore(
            ImageSource::containing($tiff),
            (new PHPLandFramebufferDriver())->dirty(FormatSpec::rgba8(), 1, 1),
            1,
        ))->toThrow(function (ImageException $e) {
            expect($e->getMessage())->toBe('TIFF image has no frame 1; it has 1.');
        });
});
