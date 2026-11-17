<?php

declare(strict_types=1);

use Jovian\Engines\Velvet\Lustrous\Rasterization\CLandRasterizationDriver;
use Jovian\Engines\Velvet\Lustrous\Rasterization\PHPLandRasterizationDriver;
use Jovian\Engines\Velvet\Tests\Fixtures\CoreOnlyScanner;
use Jovian\Engines\Velvet\Tests\Fixtures\DirectPathScanner;
use Jovian\Engines\Velvet\Tests\Fixtures\MaskPathScanner;
use Jovian\Engines\Velvet\Tests\Fixtures\PartialRasterizationDriver;
use Jovian\Engines\Velvet\Tests\Fixtures\ShapesAndBatchScanner;
use Venusian\Topside\Tests\Support\LibraryDrivers;

/**
 * Lustrous's two rasterization drivers: the PHP one always, the C one when ext-rasterize is
 * loaded. Their scanners have every tier and answer the same span bytes, and the tests over this
 * dataset hold them to that.
 */
dataset('rasterization drivers', function () {
    yield 'native' => [new PHPLandRasterizationDriver()];

    if (class_exists(RasterScanner::class)) {
        yield 'ext' => [new CLandRasterizationDriver()];
    }
});

/**
 * Those, four stand-ins for library bindings (two whose scanners leave tiers out, one that draws
 * in place, one that answers masks) and each library's: for what must hold whichever scanner does
 * the scanning. A scanner that leaves a tier out draws curved shapes as outlines through its own
 * path(), and hands back a shadow or a one-pixel line undrawn (LoweringTest).
 */
dataset('every rasterization driver', function () {
    yield 'native' => [new PHPLandRasterizationDriver()];

    if (class_exists(RasterScanner::class)) {
        yield 'ext' => [new CLandRasterizationDriver()];
    }

    yield 'a path-only scanner' => [new PartialRasterizationDriver('core-only', CoreOnlyScanner::class)];
    yield 'a scanner with shapes and a batch, no pixel tier' => [new PartialRasterizationDriver('shapes-and-batch', ShapesAndBatchScanner::class)];
    yield 'a scanner that draws its paths in place' => [new PartialRasterizationDriver('direct-path', DirectPathScanner::class)];
    yield 'a scanner that answers coverage as masks' => [new PartialRasterizationDriver('mask-path', MaskPathScanner::class)];

    foreach (LibraryDrivers::$rasterization as $name => $driver) {
        yield $name => [$driver];
    }
});
