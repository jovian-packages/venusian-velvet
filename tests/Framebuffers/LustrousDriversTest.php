<?php

use Jovian\Engines\Velvet\Lustrous\Framebuffers\CLandFramebufferDriver;
use Jovian\Engines\Velvet\Lustrous\Framebuffers\DirtyFramebuffer;
use Jovian\Engines\Velvet\Lustrous\Framebuffers\ePaperFramebuffer;
use Jovian\Engines\Velvet\Lustrous\Framebuffers\FullFramebuffer;
use Jovian\Engines\Velvet\Lustrous\Framebuffers\PagedFramebuffer;
use Jovian\Engines\Velvet\Lustrous\Framebuffers\PHPLandFramebufferDriver;
use Jovian\Engines\Velvet\Lustrous\Framebuffers\RingFramebuffer;
use Jovian\Engines\Velvet\Lustrous\Framebuffers\StoreFramebufferDriver;
use Surface\Contracts\Rendering\Framebuffers\BitDepth;
use Surface\Contracts\Rendering\Framebuffers\FormatSpec;
use Surface\Contracts\Rendering\Framebuffers\FramebufferException;
use Surface\Contracts\Rendering\Framebuffers\PixelFormat;

/*
 * Lustrous's framebuffer drivers: 'lustrous-php' and 'lustrous-c' make a full frame, and
 * 'lustrous-*-<kind>' always makes that kind. Each driver answers the name it is registered under.
 */

dataset('lustrous modules', function () {
    $modules = ['lustrous-php' => fn (...$a) => new PHPLandFramebufferDriver(...$a)];
    if (extension_loaded('fb')) {
        $modules['lustrous-c'] = fn (...$a) => new CLandFramebufferDriver(...$a);
    }

    return $modules;
});

it('makes a full frame under the module name', function (Closure $driver) {
    $d = $driver();

    expect($d->make(FormatSpec::rgba8(), 4, 3))->toBeInstanceOf(FullFramebuffer::class)
        ->and($d->driver())->toBeIn(['lustrous-php', 'lustrous-c']);
})->with('lustrous modules');

it('makes the named kind and answers its own name', function (Closure $driver, string $kind, string $class) {
    $d = $driver($kind);
    $format = $kind === 'epaper' ? new FormatSpec(PixelFormat::MONO_HORIZONTAL, BitDepth::B1) : FormatSpec::rgba8();
    $frame = $d->make($format, 8, 4);

    expect($frame)->toBeInstanceOf($class)
        ->and($frame->viewportWidth())->toBe(8)
        ->and($frame->viewportHeight())->toBe(4)
        ->and($d->driver())->toEndWith("-{$kind}");
})->with('lustrous modules')->with([
    'full' => ['full', FullFramebuffer::class],
    'dirty' => ['dirty', DirtyFramebuffer::class],
    'epaper' => ['epaper', ePaperFramebuffer::class],
    'paged' => ['paged', PagedFramebuffer::class],
    'ring' => ['ring', RingFramebuffer::class],
]);

it('names every kind make() can give', function () {
    expect(StoreFramebufferDriver::KINDS)->toBe(['full', 'dirty', 'epaper', 'paged', 'ring']);
});

it('gives a paged frame its configured rows per page, or one page of the whole height', function (Closure $driver) {
    expect($driver('paged', 'full', 2)->make(FormatSpec::rgba8(), 8, 6)->pageRows())->toBe(2)
        ->and($driver('paged', 'full', 2)->make(FormatSpec::rgba8(), 8, 6)->pages())->toBe(3)
        ->and($driver('paged')->make(FormatSpec::rgba8(), 8, 6)->pages())->toBe(1);
})->with('lustrous modules');

it('gives a ring its configured frame count', function (Closure $driver) {
    expect($driver('ring', 'full', 0, 3)->make(FormatSpec::rgba8(), 8, 4)->frames())->toBe(3);
})->with('lustrous modules');

it('refuses a kind it does not have', function (Closure $driver) {
    $driver('triple');
})->with('lustrous modules')->throws(FramebufferException::class);
