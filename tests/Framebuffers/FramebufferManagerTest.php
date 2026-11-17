<?php

declare(strict_types=1);

use Jovian\Engines\Velvet\Lustrous\Framebuffers\CLandFramebufferDriver;
use Jovian\Engines\Velvet\Lustrous\Framebuffers\PHPLandFramebufferDriver;
use Surface\Contracts\Rendering\Framebuffers\FramebufferException;

require_once __DIR__.'/../Support/VelvetApp.php';

it('gives Velvet the framebuffer module config/velvet.php names, Lustrous\'s full frame in PHP when it names none', function (): void {
    velvetApp();
    expect(app('velvet.framebuffers')->driver()->driver())->toBe('lustrous-php-full');

    velvetApp(['velvet.cpu.framebuffers.default' => 'lustrous-php-ring']);
    expect(app('velvet.framebuffers')->driver()->driver())->toBe('lustrous-php-ring');
});

it('builds native when named, whatever is loaded', function (): void {
    expect(framebuffers()->driver('native'))->toBeInstanceOf(PHPLandFramebufferDriver::class)
        ->and(framebuffers(['rendering.framebuffer.default' => 'native'])->driver()->driver())->toBe('lustrous-php');
});

it('takes its default from config', function (): void {
    expect(framebuffers(['rendering.framebuffer.default' => 'ext'])->getDefaultDriver())->toBe('ext');
});

it('builds the ext driver when ext-fb is loaded', function (): void {
    expect(framebuffers()->driver('ext'))->toBeInstanceOf(CLandFramebufferDriver::class)
        ->and(framebuffers()->driver('ext')->driver())->toBe('lustrous-c');
})->skip(! CLandFramebufferDriver::available(), 'ext-fb 0.11.1 is not loaded in this PHP.');

it('refuses the ext driver without ext-fb 0.11.1, naming the extension and the PHP', function (): void {
    expect(fn () => framebuffers()->driver('ext'))->toThrow(FramebufferException::class, 'needs ext-fb 0.11.1 or newer loaded in this PHP ('.PHP_BINARY.')');
})->skip(CLandFramebufferDriver::available(), 'ext-fb 0.11.1 is loaded in this PHP.');

it('refuses a driver it does not have', function (): void {
    expect(fn () => framebuffers()->driver('gpu'))->toThrow(InvalidArgumentException::class, 'Driver [gpu] not supported');
});
