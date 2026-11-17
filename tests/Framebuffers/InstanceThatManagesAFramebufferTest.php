<?php

use Jovian\Engines\Velvet\Lustrous\Framebuffers\DirtyFramebuffer;
use Jovian\Engines\Velvet\Lustrous\Framebuffers\ePaperFramebuffer;
use Jovian\Engines\Velvet\Lustrous\Framebuffers\FullFramebuffer;
use Jovian\Engines\Velvet\Lustrous\Framebuffers\PagedFramebuffer;
use Jovian\Engines\Velvet\Lustrous\Framebuffers\PHPLandFramebufferDriver;
use Jovian\Engines\Velvet\Lustrous\Framebuffers\RingFramebuffer;
use VelvetGE\Contracts\Framebuffers\CompositingFramebuffer;
use VelvetGE\Contracts\Framebuffers\GradientFramebuffer;
use Surface\Contracts\Rendering\Framebuffers\BitDepth;
use Surface\Contracts\Rendering\Framebuffers\DamageGranularity;
use Surface\Contracts\Rendering\Framebuffers\DamageTrackingFramebuffer;
use VelvetGE\Contracts\Framebuffers\EffectsFramebuffer;
use Surface\Contracts\Rendering\Framebuffers\FormatSpec;
use Surface\Contracts\Rendering\Framebuffers\FramebufferException;
use Surface\Contracts\Rendering\Framebuffers\InstanceThatManagesAFramebuffer;
use VelvetGE\Contracts\Framebuffers\PagedFramebuffer as PagedContract;
use Surface\Contracts\Rendering\Framebuffers\PixelFormat;
use Surface\Contracts\Rendering\Framebuffers\Region;
use VelvetGE\Contracts\Framebuffers\RingFramebuffer as RingContract;
use VelvetGE\Contracts\Framebuffers\ScrollingFramebuffer;
use VelvetGE\Contracts\Framebuffers\ePaperFramebuffer as ePaperContract;

/*
 * InstanceThatManagesAFramebuffer is what every framebuffer module promises. These tests pin the
 * contract's method list, then hold each of Lustrous's kinds to every promise, so a method dropped
 * from the contract or a kind that stops honouring one fails here.
 *
 * Each kind is tested in a format it takes, with two pixel values that format holds, its blank (what
 * clear() leaves: 0 in RGBA8, the paper on an ePaper), and RGBA8 bytes the format holds exactly. A
 * ring is a swap chain: writes go to the frame being drawn and read-outs show the frame in front, so
 * it is presented before anything is read back.
 */

const W = 8;
const H = 4;

/** @return array{0: InstanceThatManagesAFramebuffer, 1: array{int, int}, 2: int, 3: string} */
function kind(string $name): array
{
    $minter = new PHPLandFramebufferDriver();
    $rgba8 = FormatSpec::rgba8();
    $mono = new FormatSpec(PixelFormat::MONO_HORIZONTAL, BitDepth::B1);
    $black = "\x00\x00\x00\xFF";
    $white = "\xFF\xFF\xFF\xFF";
    $inks = '';
    for ($i = 0; $i < W * H; $i++) {
        $inks .= $i % 3 === 0 ? $white : $black;
    }

    return match ($name) {
        'full' => [new FullFramebuffer($minter, $rgba8, W, H), [0x11223344, 0xAABBCCDD], 0, random_bytes(W * H * 4)],
        'dirty' => [new DirtyFramebuffer($minter, $rgba8, W, H), [0x11223344, 0xAABBCCDD], 0, random_bytes(W * H * 4)],
        'paged' => [new PagedFramebuffer($minter, $rgba8, W, H, H), [0x11223344, 0xAABBCCDD], 0, random_bytes(W * H * 4)],
        'ring' => [new RingFramebuffer($minter, $rgba8, W, H, 2), [0x11223344, 0xAABBCCDD], 0, random_bytes(W * H * 4)],
        'epaper' => (function () use ($minter, $mono, $inks) {
            $frame = new ePaperFramebuffer($minter, $mono, W, H);

            return [$frame, [0, 1], $frame->paper(), $inks];
        })(),
    };
}

/** What a read-out shows: a ring shows the frame it last presented. */
function shown(InstanceThatManagesAFramebuffer $frame): InstanceThatManagesAFramebuffer
{
    if ($frame instanceof RingContract) {
        $frame->present();
    }

    return $frame;
}

dataset('lustrous kinds', ['full', 'dirty', 'epaper', 'paged', 'ring']);

it('promises exactly the operations every framebuffer library has', function () {
    $methods = array_map(fn (ReflectionMethod $m) => $m->name, (new ReflectionClass(InstanceThatManagesAFramebuffer::class))->getMethods());
    sort($methods);

    expect($methods)->toBe([
        'blitFrom', 'blitTo', 'clear', 'damageGranularity', 'drewInto', 'dump', 'fill', 'flush', 'flushRegion',
        'getPixel', 'hostFormat', 'memory', 'pointer', 'preservesContentsOnPresent', 'scratch', 'setPixel',
        'setPixels', 'setRegion', 'setSegment', 'toRgba8', 'viewportHeight', 'viewportWidth', 'writeRgba8',
    ]);
});

it('keeps compositing, scrolling and effects out of the core', function () {
    $core = array_map(fn (ReflectionMethod $m) => $m->name, (new ReflectionClass(InstanceThatManagesAFramebuffer::class))->getMethods());

    foreach ([CompositingFramebuffer::class, GradientFramebuffer::class, ScrollingFramebuffer::class, EffectsFramebuffer::class] as $optional) {
        $own = array_filter((new ReflectionClass($optional))->getMethods(), fn (ReflectionMethod $m) => $m->class === $optional);
        expect($own)->not->toBeEmpty();
        foreach ($own as $method) {
            expect(in_array($method->name, $core, true))->toBeFalse("{$optional}::{$method->name} is also in the core");
        }
    }
});

it('declares the contracts each kind honours', function (string $name, ?string $own) {
    [$frame] = kind($name);

    // Every Lustrous kind is the core plus every optional contract, as it was through the old Framebuffer.
    foreach ([InstanceThatManagesAFramebuffer::class, CompositingFramebuffer::class, GradientFramebuffer::class, ScrollingFramebuffer::class, EffectsFramebuffer::class] as $contract) {
        expect($frame)->toBeInstanceOf($contract);
    }
    if ($own !== null) {
        expect($frame)->toBeInstanceOf($own);
    }
})->with([
    'full' => ['full', null],
    'dirty' => ['dirty', DamageTrackingFramebuffer::class],
    'epaper' => ['epaper', ePaperContract::class],
    'paged' => ['paged', PagedContract::class],
    'ring' => ['ring', RingContract::class],
]);

it('answers its shape', function (string $name) {
    [$frame] = kind($name);

    expect($frame->viewportWidth())->toBe(W)
        ->and($frame->viewportHeight())->toBe(H)
        ->and($frame->hostFormat())->toBeInstanceOf(FormatSpec::class)
        ->and($frame->damageGranularity())->toBeInstanceOf(DamageGranularity::class)
        ->and($frame->preservesContentsOnPresent())->toBeBool();
})->with('lustrous kinds');

it('fills every pixel, and clears every pixel to its blank', function (string $name) {
    [$frame, [$a], $blank] = kind($name);

    $frame->fill($a);
    for ($y = 0; $y < H; $y++) {
        for ($x = 0; $x < W; $x++) {
            expect($frame->getPixel($x, $y))->toBe($a);
        }
    }

    $frame->clear();
    for ($y = 0; $y < H; $y++) {
        for ($x = 0; $x < W; $x++) {
            expect($frame->getPixel($x, $y))->toBe($blank);
        }
    }
})->with('lustrous kinds');

it('sets single pixels, lists of pixels and coordinate sets', function (string $name) {
    [$frame, [$a, $b], $blank] = kind($name);
    $other = $blank === $a ? $b : $a;
    $frame->clear();

    expect($frame->setPixel(1, 2, $other))->toBe($frame)
        ->and($frame->getPixel(1, 2))->toBe($other)
        ->and($frame->getPixel(2, 1))->toBe($blank);

    $frame->clear()->setPixels([[0, 0, $other], [W - 1, H - 1, $other]]);
    expect($frame->getPixel(0, 0))->toBe($other)
        ->and($frame->getPixel(W - 1, H - 1))->toBe($other)
        ->and($frame->getPixel(1, 1))->toBe($blank);

    $frame->clear()->setRegion([[2, 0], [3, 1]], $other);
    expect($frame->getPixel(2, 0))->toBe($other)
        ->and($frame->getPixel(3, 1))->toBe($other)
        ->and($frame->getPixel(2, 1))->toBe($blank);
})->with('lustrous kinds');

it('throws for a pixel outside the surface', function (string $name) {
    [$frame] = kind($name);

    expect(fn () => $frame->getPixel(W, 0))->toThrow(FramebufferException::class)
        ->and(fn () => $frame->setPixel(0, H, 0))->toThrow(FramebufferException::class);
})->with('lustrous kinds');

it('clips a segment that straddles the edge instead of throwing', function (string $name) {
    [$frame, [$a, $b], $blank] = kind($name);
    $other = $blank === $a ? $b : $a;
    $frame->clear()->setSegment(W - 2, H - 2, 5, 5, $other);

    expect($frame->getPixel(W - 1, H - 1))->toBe($other)
        ->and($frame->getPixel(W - 2, H - 2))->toBe($other)
        ->and($frame->getPixel(W - 3, H - 3))->toBe($blank);
})->with('lustrous kinds');

it('takes RGBA8 in and gives the same bytes back out', function (string $name) {
    [$frame, , , $bytes] = kind($name);
    $frame->writeRgba8($bytes, W, H);
    shown($frame);

    expect($frame->toRgba8())->toBe($bytes)
        ->and($frame->flush(FormatSpec::rgba8()))->toBe($bytes)
        ->and($frame->flush(FormatSpec::rgba8(), true))->toBe(array_values(unpack('C*', $bytes)))
        ->and($frame->dump())->toBeString()->not->toBe('');

    $sub = '';
    for ($y = 1; $y < 3; $y++) {
        $sub .= substr($bytes, ($y * W + 2) * 4, 3 * 4);
    }
    expect($frame->flushRegion(new Region(2, 1, 3, 2), FormatSpec::rgba8()))->toBe($sub);
})->with('lustrous kinds');

it('refuses RGBA8 of the wrong size and writes nothing', function (string $name) {
    [$frame, , , $bytes] = kind($name);
    $frame->writeRgba8($bytes, W, H);

    expect(fn () => $frame->writeRgba8(str_repeat("\xFF", 7), 2, 1))->toThrow(FramebufferException::class);
    expect(shown($frame)->toRgba8())->toBe($bytes);
})->with('lustrous kinds');

it('blits to and from another framebuffer', function (string $name) {
    [$a, , , $bytes] = kind($name);
    $a->writeRgba8($bytes, W, H);
    shown($a);
    $b = new FullFramebuffer(new PHPLandFramebufferDriver(), FormatSpec::rgba8(), W, H);

    expect($a->blitTo($b))->toBeInstanceOf(InstanceThatManagesAFramebuffer::class)
        ->and($b->toRgba8())->toBe($bytes);

    [$c, , , $others] = kind($name);
    $b->writeRgba8($others, W, H);
    expect($a->blitFrom($b))->toBeInstanceOf(InstanceThatManagesAFramebuffer::class)
        ->and(shown($a)->toRgba8())->toBe($others);
})->with('lustrous kinds');

it('makes a blank RGBA8 scratch frame of its own driver', function (string $name) {
    [$frame] = kind($name);
    $scratch = $frame->scratch(3, 2);

    expect($scratch)->toBeInstanceOf(InstanceThatManagesAFramebuffer::class)
        ->and($scratch->viewportWidth())->toBe(3)
        ->and($scratch->viewportHeight())->toBe(2)
        ->and($scratch->hostFormat()->equals(FormatSpec::rgba8()))->toBeTrue()
        ->and($scratch->toRgba8())->toBe(str_repeat("\0", 3 * 2 * 4));
})->with('lustrous kinds');

it('lends no ticket when PHP holds the bytes, and refuses drewInto', function (string $name) {
    [$frame] = kind($name);

    // PHP-land's bytes stay opaque inside the store: C reaches them only by an explicit read-back
    expect($frame->memory())->toBeNull()
        ->and($frame->pointer())->toBe(0)
        ->and(fn () => $frame->drewInto(new Region(0, 0, 1, 1)))->toThrow(FramebufferException::class);
})->with('lustrous kinds');
