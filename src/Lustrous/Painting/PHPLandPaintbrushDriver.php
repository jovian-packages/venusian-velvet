<?php

namespace Jovian\Engines\Velvet\Lustrous\Painting;

use Surface\Contracts\Rendering\Painting\Brush;
use VelvetGE\Painting\BrushPaintingDriver;

/** VelvetGE's painter with no brush: every command carried out in PHP over Rendering's rasterizer and framebuffer. Always available: what 'auto' takes last. */
class PHPLandPaintbrushDriver extends BrushPaintingDriver
{
    public function driver(): string
    {
        return 'lustrous-php';
    }

    public function brush(): ?Brush
    {
        return null;
    }
}
