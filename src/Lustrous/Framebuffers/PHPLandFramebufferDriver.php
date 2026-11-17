<?php

namespace Jovian\Engines\Velvet\Lustrous\Framebuffers;

use Surface\Contracts\Rendering\Framebuffers\FormatSpec;
use VelvetGE\Framebuffers\PHP\PHPLandPixelStore;

/** The five kinds with their bytes in PHP strings. Always available: what 'auto' takes last. */
class PHPLandFramebufferDriver extends StoreFramebufferDriver
{
    protected function module(): string
    {
        return 'lustrous-php';
    }

    public function mint(FormatSpec $format, int $width, int $height): PHPLandPixelStore
    {
        return new PHPLandPixelStore($format, $width, $height);
    }
}
