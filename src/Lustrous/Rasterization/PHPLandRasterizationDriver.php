<?php

namespace Jovian\Engines\Velvet\Lustrous\Rasterization;

use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\Contracts\Rendering\Rasterization\Edges;
use Surface\Contracts\Rendering\Rasterization\Scanner;
use VelvetGE\Rasterization\ScannerRasterizationDriver;

/** The scanning in PHP: runs anywhere. */
class PHPLandRasterizationDriver extends ScannerRasterizationDriver
{
    public function driver(): string
    {
        return 'lustrous-php';
    }

    protected function scanner(Region $clip, Edges $edges): Scanner
    {
        return new PHPLandScanner($clip, $edges);
    }
}
