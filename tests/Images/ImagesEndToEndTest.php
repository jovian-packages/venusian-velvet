<?php

declare(strict_types=1);

use Surface\Contracts\Graphics\Backend;
use Surface\Contracts\Graphics\DrawingContext;
use Surface\Contracts\Graphics\Feature;
use Surface\Images\ImageLibrary;
use VelvetGE\VelvetDevice;

require_once __DIR__.'/../Support/VelvetApp.php';

/*
 * A PNG file on disk, through Images, into a real Velvet engine, and drawn with image(): the path an
 * application takes. The PNG is four opaque pixels whose values sit here: row 0 red, green; row 1
 * blue, white. Opaque pixels are the file's values on every decoder. The image is drawn at (3, 2)
 * on an 8×6 surface cleared to transparent, so pixels around it must stay 0. read() answers RGBA8.
 *
 * Each case pairs Lustrous's frames with the decoder that writes them: PHP frames take the gd
 * decoder through the store, C frames take ext-imgdec at their address.
 */

function endToEndPng(): string
{
    $image = imagecreatetruecolor(2, 2);
    imagesetpixel($image, 0, 0, 0xFF0000);
    imagesetpixel($image, 1, 0, 0x00FF00);
    imagesetpixel($image, 0, 1, 0x0000FF);
    imagesetpixel($image, 1, 1, 0xFFFFFF);
    $path = tempnam(sys_get_temp_dir(), 'e2e').'.png';
    imagepng($image, $path);

    return $path;
}

/** RGBA8 of pixel (x, y) in an 8-wide read(). */
function e2ePixel(string $rgba, int $x, int $y): string
{
    return bin2hex(substr($rgba, ($y * 8 + $x) * 4, 4));
}

it('draws a PNG file loaded through Images at the place asked, on Lustrous\'s frames with the decoder that writes them', function (string $frames, string $decoder, string $extension): void {
    extension_loaded($extension) || throw new RuntimeException("ext-{$extension} is not loaded in this PHP; the {$decoder} case needs it.");
    velvetApp(['velvet.cpu.framebuffers.default' => $frames, 'velvet.cpu.painters.default' => $frames, 'images.decoders' => [$decoder]]);
    $engine = new VelvetDevice(Backend::CPU);
    $library = new ImageLibrary(app('images.decoders'), 65535, 1 << 26, fn () => $engine);
    $path = endToEndPng();

    try {
        $loaded = $library->load($path);
        $target = $engine->offscreen(8, 6);
        $target->render(fn (DrawingContext $g) => $g->image($loaded, 3, 2));
        $rgba = $target->read();

        expect($engine->has(Feature::IMAGES))->toBeTrue()
            ->and([$loaded->width(), $loaded->height()])->toBe([2, 2])
            ->and(e2ePixel($rgba, 3, 2))->toBe('ff0000ff')
            ->and(e2ePixel($rgba, 4, 2))->toBe('00ff00ff')
            ->and(e2ePixel($rgba, 3, 3))->toBe('0000ffff')
            ->and(e2ePixel($rgba, 4, 3))->toBe('ffffffff')
            ->and(e2ePixel($rgba, 2, 2))->toBe('00000000')
            ->and(e2ePixel($rgba, 5, 2))->toBe('00000000')
            ->and(e2ePixel($rgba, 3, 1))->toBe('00000000')
            ->and(e2ePixel($rgba, 3, 4))->toBe('00000000');
    } finally {
        $library->release();
        @unlink($path);
    }
})->with([
    'PHP frames, gd' => ['lustrous-php', 'lustrous-php', 'gd'],
    'C frames, ext-imgdec' => ['lustrous-c', 'lustrous-c', 'imgdec'],
]);
