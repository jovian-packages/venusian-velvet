<?php

namespace Jovian\Engines\Velvet\Lustrous\Raycasting;

use Surface\Contracts\Rendering\Raycasting\Grid;
use Surface\Contracts\Rendering\Raycasting\Marcher;
use VelvetGE\Raycasting\MarcherRaycastingDriver;

/** Lustrous's casting in PHP. Always available. */
class PHPLandRaycastingDriver extends MarcherRaycastingDriver
{
    public function driver(): string
    {
        return 'lustrous-php';
    }

    protected function marcher(Grid $grid): Marcher
    {
        return new PHPLandMarcher($grid);
    }
}
