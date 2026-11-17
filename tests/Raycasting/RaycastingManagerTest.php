<?php

declare(strict_types=1);

use Jovian\Engines\Velvet\Lustrous\Raycasting\CLandRaycastingDriver;
use Jovian\Engines\Velvet\Lustrous\Raycasting\PHPLandRaycastingDriver;
use Jovian\Engines\Velvet\Providers\VenusianVelvetServiceProvider;
use Surface\Contracts\Rendering\Raycasting\RaycastException;

it('registers lustrous-php, lustrous-c where ext-raycast is new enough, and an auto that takes lustrous-c when it can and lustrous-php otherwise', function (): void {
    $drivers = VenusianVelvetServiceProvider::raycastingDrivers();
    $c = CLandRaycastingDriver::available();

    expect(array_keys($drivers))->toBe($c ? ['lustrous-php', 'lustrous-c', 'auto'] : ['lustrous-php', 'auto'])
        ->and($drivers['lustrous-php']())->toBeInstanceOf(PHPLandRaycastingDriver::class)
        ->and($drivers['lustrous-php']()->driver())->toBe('lustrous-php')
        ->and($drivers['auto']()->driver())->toBe($c ? 'lustrous-c' : 'lustrous-php');
    if ($c) {
        expect($drivers['lustrous-c']())->toBeInstanceOf(CLandRaycastingDriver::class)
            ->and($drivers['lustrous-c']()->driver())->toBe('lustrous-c');
    }
});

it('refuses lustrous-c without ext-raycast, naming the driver, the extension and the PHP', function (): void {
    expect(fn () => new CLandRaycastingDriver())->toThrow(RaycastException::class, "The 'lustrous-c' raycasting driver needs ext-raycast 0.11.0 or newer loaded in this PHP (".PHP_BINARY.')');
})->skip(CLandRaycastingDriver::available(), 'ext-raycast is loaded in this PHP.');

it('mints raycasters that name the driver they came from', function (): void {
    expect((new PHPLandRaycastingDriver())->caster(raycastRoom())->driver())->toBe('lustrous-php');
    if (CLandRaycastingDriver::available()) {
        expect((new CLandRaycastingDriver())->caster(raycastRoom())->driver())->toBe('lustrous-c');
    }
});
