<?php

declare(strict_types=1);

use Jovian\Engines\Velvet\Lustrous\Raycasting\CLandRaycastingDriver;
use Jovian\Engines\Velvet\Lustrous\Raycasting\PHPLandRaycastingDriver;
use Venusian\Topside\Tests\Fixtures\LibraryRaycastingDriver;

/**
 * Every raycasting driver this PHP can run: Lustrous's lustrous-php always, and with ext-raycast its lustrous-c and a
 * stand-in for a library binding whose marcher draws in place (ext-raycast stands in for the library).
 */
dataset('raycast drivers', function () {
    yield 'lustrous-php' => [new PHPLandRaycastingDriver()];

    if (CLandRaycastingDriver::available()) {
        yield 'lustrous-c' => [new CLandRaycastingDriver()];
        yield 'a library marcher that draws in place' => [new LibraryRaycastingDriver()];
    }
});
