<?php

namespace Jovian\Engines\Velvet\Lustrous\Stroking;

use Surface\Contracts\Rendering\Rasterization\StrokeGeometry;
use Surface\Rendering\Stroking\StrokingDriver;

/** 'lustrous-php': Lustrous's stroker in PHP, with dashes. Always available. */
class PHPLandStrokingDriver extends StrokingDriver
{
    private ?PHPStroker $stroker = null;

    public function driver(): string
    {
        return 'lustrous-php';
    }

    public function stroker(): StrokeGeometry
    {
        return $this->stroker ??= new PHPStroker();
    }
}
