<?php

namespace Jovian\Engines\Velvet\Lustrous\Stroking;

use Surface\Contracts\Rendering\Rasterization\RasterizeException;
use Surface\Contracts\Rendering\Rasterization\StrokeGeometry;
use Surface\Rendering\Stroking\StrokingDriver;

/** 'lustrous-c': Lustrous's stroker in C (ext-rasterize's RasterStroker), with dashes. */
class CLandStrokingDriver extends StrokingDriver
{
    private ?CLandStroker $stroker = null;

    /**
     * @throws RasterizeException When ext-rasterize is not loaded, or has no RasterStroker.
     */
    public function __construct()
    {
        $this->stroker = new CLandStroker();
    }

    public function driver(): string
    {
        return 'lustrous-c';
    }

    public function stroker(): StrokeGeometry
    {
        return $this->stroker;
    }
}
