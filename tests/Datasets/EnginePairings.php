<?php

declare(strict_types=1);

use Jovian\Engines\Velvet\Lustrous\Framebuffers\CLandFramebufferDriver;
use Jovian\Engines\Velvet\Lustrous\Framebuffers\PHPLandFramebufferDriver;
use Jovian\Engines\Velvet\Lustrous\Rasterization\CLandRasterizationDriver;
use Jovian\Engines\Velvet\Lustrous\Rasterization\PHPLandRasterizationDriver;

/** Every pairing of a rasterize driver with a framebuffer driver this PHP can run: what a software engine can be built from. */
dataset('engine pairings', function () {
    $rasterize = ['native' => new PHPLandRasterizationDriver()];
    $framebuffers = ['native' => new PHPLandFramebufferDriver()];
    if (class_exists(RasterScanner::class)) {
        $rasterize['extended'] = new CLandRasterizationDriver();
    }
    if (class_exists(FbBuffer::class)) {
        $framebuffers['extended'] = new CLandFramebufferDriver();
    }

    foreach ($rasterize as $r => $raster) {
        foreach ($framebuffers as $f => $framebuffer) {
            yield "{$r} geometry, {$f} bytes" => [$raster, $framebuffer];
        }
    }
});
