<?php

declare(strict_types=1);

use Jovian\Engines\Velvet\Lustrous\Framebuffers\PHPLandFramebufferDriver;
use Surface\Contracts\Rendering\Framebuffers\FormatSpec;
use Surface\Contracts\Rendering\Raycasting\Texture;

it('takes a texture as RGBA8 bytes, or from a framebuffer', function (): void {
    $frame = (new PHPLandFramebufferDriver())->full(FormatSpec::rgba8(), 2, 1)->writeRgba8(hex2bin('ff0000ff00ff00ff'), 2, 1);
    $texture = Texture::of($frame);

    expect([$texture->width, $texture->height, bin2hex($texture->rgba8)])->toBe([2, 1, 'ff0000ff00ff00ff']);
});
