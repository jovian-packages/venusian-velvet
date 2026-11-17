<?php

declare(strict_types=1);

use Surface\Contracts\Rendering\Rasterization\RasterizeException;
use Jovian\Engines\Velvet\Lustrous\Rasterization\CLandRasterizationDriver;
use Jovian\Engines\Velvet\Lustrous\Rasterization\PHPLandRasterizationDriver;

require_once __DIR__.'/../Support/VelvetApp.php';

it('gives Velvet the rasterizer config/velvet.php names, and its auto takes Lustrous\'s C one where ext-rasterize is loaded', function (): void {
    velvetApp();
    expect(app('velvet.rasterizers')->driver()->driver())->toBe('lustrous-php');

    velvetApp(['velvet.cpu.rasterizers.default' => 'auto']);
    expect(app('velvet.rasterizers')->driver()->driver())->toBe(class_exists(RasterScanner::class) ? 'lustrous-c' : 'lustrous-php');
});

it('builds native when named, whatever is loaded', function (): void {
    expect(rasterize()->driver('native'))->toBeInstanceOf(PHPLandRasterizationDriver::class)
        ->and(rasterize(['rendering.rasterizer.default' => 'native'])->driver()->driver())->toBe('lustrous-php');
});

it('takes its default from config', function (): void {
    expect(rasterize(['rendering.rasterizer.default' => 'ext'])->getDefaultDriver())->toBe('ext');
});

it('builds the extended driver when ext-rasterize is loaded', function (): void {
    expect(rasterize()->driver('ext'))->toBeInstanceOf(CLandRasterizationDriver::class)
        ->and(rasterize()->driver('ext')->driver())->toBe('lustrous-c');
})->skip(! class_exists(RasterScanner::class), 'ext-rasterize is not loaded in this PHP.');

it('refuses the extended driver without ext-rasterize, naming the extension and the PHP', function (): void {
    expect(fn () => rasterize()->driver('ext'))->toThrow(RasterizeException::class, 'needs ext-rasterize 0.10 or newer loaded in this PHP ('.PHP_BINARY.')');
})->skip(class_exists(RasterScanner::class), 'ext-rasterize is loaded in this PHP.');

it('refuses a driver it does not have', function (): void {
    expect(fn () => rasterize()->driver('gpu'))->toThrow(InvalidArgumentException::class, 'Driver [gpu] not supported');
});

it('registers lustrous-php, lustrous-c where ext-rasterize is loaded, and an auto that takes lustrous-c when it can and lustrous-php otherwise', function (): void {
    $drivers = \Jovian\Engines\Velvet\Providers\VenusianVelvetServiceProvider::rasterizationDrivers();
    $c = extension_loaded('rasterize') || extension_loaded('velvet');

    expect(array_keys($drivers))->toBe($c ? ['lustrous-php', 'lustrous-c', 'auto'] : ['lustrous-php', 'auto'])
        ->and($drivers['lustrous-php']()->driver())->toBe('lustrous-php')
        ->and($drivers['auto']()->driver())->toBe($c ? 'lustrous-c' : 'lustrous-php');
    if ($c) {
        expect($drivers['lustrous-c']()->driver())->toBe('lustrous-c');
    }
});
