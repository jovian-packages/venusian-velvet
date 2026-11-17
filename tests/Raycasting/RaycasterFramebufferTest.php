<?php

declare(strict_types=1);

use Jovian\Engines\Velvet\Lustrous\Framebuffers\PHPLandFramebufferDriver;
use Surface\Contracts\Rendering\Framebuffers\FormatSpec;
use Surface\Contracts\Rendering\Raycasting\RaycastDriver;
use Surface\Contracts\Rendering\Raycasting\Scenery;

it('gives pixels a framebuffer takes as they are', function (RaycastDriver $driver): void {
    $pixels = $driver->caster(raycastRoom())->view(eastward(2.5, 2.5), 8, 6, new Scenery([1 => 0xFF0000FF, 2 => 0xFF0000FF], 0x111111FF, 0x222222FF));
    $frame = (new PHPLandFramebufferDriver())->full(FormatSpec::rgba8(), 8, 6)->writeRgba8($pixels, 8, 6);

    expect($frame->toRgba8())->toBe($pixels)
        ->and($frame->getPixel(0, 0))->toBe(0x111111FF)
        ->and($frame->getPixel(4, 3))->toBe(0xFF0000FF)
        ->and($frame->getPixel(7, 5))->toBe(0x222222FF);
})->with('raycast drivers');
