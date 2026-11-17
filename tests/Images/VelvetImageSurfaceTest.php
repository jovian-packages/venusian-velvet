<?php

declare(strict_types=1);

use Jovian\Engines\Velvet\Lustrous\Framebuffers\CLandFramebufferDriver;
use Jovian\Engines\Velvet\Lustrous\Framebuffers\PHPLandFramebufferDriver;
use Jovian\Engines\Velvet\Lustrous\Framebuffers\StoreFramebuffer;
use Jovian\Engines\Velvet\Lustrous\Rasterization\PHPLandRasterizationDriver;
use Surface\Contracts\Graphics\ActorStage;
use Surface\Contracts\Graphics\Backend;
use Surface\Contracts\Graphics\DrawingException;
use Surface\Contracts\Graphics\DrawingSurface;
use Surface\Contracts\Graphics\Feature;
use Surface\Contracts\Graphics\GraphicsEngine;
use Surface\Contracts\Images\ImageFormat;
use Surface\Contracts\Images\ImageSource;
use Surface\Contracts\Rendering\Framebuffers\FormatSpec;
use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\Graphics\GfxDevice;
use VelvetGE\Painting\VelvetPainter;
use VelvetGE\VelvetDevice;
use VelvetGE\VelvetSurface;

/*
 * Velvet's offscreen surface is the frame a decoder writes. A PHP frame lends no address and
 * hands over the framebuffer. A C frame lends its memory and, on close, records only the region
 * it was given. Opening twice without closing is refused. Velvet does not decode files itself.
 */

final class VelvetNamedEngine extends GfxDevice
{
    public function __construct()
    {
        parent::__construct(Backend::CPU);
    }

    public function name(): string
    {
        return 'velvet';
    }

    public function has(Feature $feature): bool
    {
        return false;
    }

    public function surface(ActorStage $stage, ?FormatSpec $format = null): DrawingSurface
    {
        throw new LogicException('This engine has no stage.');
    }

    public function offscreen(int $width, int $height, ?FormatSpec $format = null): DrawingSurface
    {
        throw new LogicException("offscreen {$width}x{$height}");
    }
}

function velvetSurface(GraphicsEngine $engine, StoreFramebuffer $frame): VelvetSurface
{
    return new VelvetSurface($engine, $frame, new VelvetPainter($frame, new PHPLandRasterizationDriver()));
}

it('lends a PHP frame no address, and close does not ask it for one', function (): void {
    $frame = (new PHPLandFramebufferDriver())->dirty(FormatSpec::rgba8(), 4, 3);
    $surface = velvetSurface(new VelvetNamedEngine(), $frame);

    expect($surface->openForDecoder())->toBeNull()
        ->and($surface->decoderStore())->toBe($frame);

    $surface->closeDecoder(new Region(1, 1, 2, 1));

    expect($frame->written())->toBe([]);
});

it('records only the region a C frame was closed with, and refuses a second open', function (): void {
    $frame = (new CLandFramebufferDriver())->dirty(FormatSpec::rgba8(), 4, 3);
    $surface = velvetSurface(new VelvetNamedEngine(), $frame);
    $memory = $surface->openForDecoder();

    expect($memory)->not->toBeNull()
        ->and($memory->width)->toBe(4)
        ->and($memory->height)->toBe(3)
        ->and($memory->address)->not->toBe(0)
        ->and($surface->decoderStore())->toBeNull()
        ->and(fn () => $surface->openForDecoder())->toThrow(DrawingException::class, 'velvet');

    $frame->beginEpoch();
    $surface->closeDecoder(new Region(1, 1, 2, 1));

    expect($frame->written())->toEqual([new Region(1, 1, 2, 1)]);

    $again = $surface->openForDecoder();
    expect($again)->not->toBeNull()
        ->and($again->width)->toBe(4)
        ->and($again->height)->toBe(3);
});

it('refuses load() by name, because Velvet does not decode a file itself', function (): void {
    $declared = (new ReflectionMethod(VelvetDevice::class, 'load'))->getDeclaringClass()->getName();
    $engine = new VelvetNamedEngine();
    $path = tempnam(sys_get_temp_dir(), 'png');
    if ($path === false) {
        throw new RuntimeException('A temporary image path could not be made.');
    }
    file_put_contents($path, "\x89PNG\r\n\x1a\n");

    try {
        expect($declared)->toBe(GfxDevice::class)
            ->and($engine->loads(ImageFormat::PNG))->toBeFalse()
            ->and(fn () => $engine->load(ImageSource::path($path)))->toThrow(DrawingException::class, 'velvet')
            ->and(fn () => $engine->load(ImageSource::path($path)))->toThrow(DrawingException::class, 'PNG');
    } finally {
        unlink($path);
    }
});
