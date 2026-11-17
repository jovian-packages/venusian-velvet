<?php

use Jovian\Engines\Velvet\Lustrous\Framebuffers\CLandFramebufferDriver;
use Jovian\Engines\Velvet\Lustrous\Framebuffers\FullFramebuffer;
use Jovian\Engines\Velvet\Lustrous\Framebuffers\PHPLandFramebufferDriver;
use Surface\Contracts\Rendering\Framebuffers\BitDepth;
use Surface\Contracts\Rendering\Framebuffers\DamageGranularity;
use Surface\Contracts\Rendering\Framebuffers\Filter;
use Surface\Contracts\Rendering\Framebuffers\FormatSpec;
use Surface\Contracts\Rendering\Framebuffers\InstanceThatManagesAFramebuffer;
use Surface\Contracts\Rendering\Framebuffers\PixelFormat;
use Surface\Contracts\Rendering\Framebuffers\PixelMemory;
use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\NutsAndBolts\Affine;

/*
 * C frames hand each other their tickets (PixelMemory), and Lustrous-C works from one where it is,
 * through ext-fb's blit() and paintBuffer(). PHP-land lends none and takes none: across it, data
 * is read back. Held to the RGBA8 read-back each replaces (same bytes), and to never reading a C
 * source back.
 */

dataset('ticket formats', [
    'rgba8' => [FormatSpec::rgba8()],
    'bgra8' => [FormatSpec::bgra8()],
    'rgb888' => [new FormatSpec(PixelFormat::ROW_MAJOR, BitDepth::B24)],
    'rgb565' => [new FormatSpec(PixelFormat::ROW_MAJOR, BitDepth::B16)],
    'mono' => [new FormatSpec(PixelFormat::MONO_HORIZONTAL, BitDepth::B1)],
]);

dataset('ticket stores', function () {
    $stores = ['php' => [fn () => new PHPLandFramebufferDriver()]];
    if (extension_loaded('fb')) {
        $stores['c'] = [fn () => new CLandFramebufferDriver()];
    }

    return $stores;
});

function ticket_frame(Closure $driver, FormatSpec $format, int $w, int $h): FullFramebuffer
{
    $frame = new FullFramebuffer($driver(), $format, $w, $h);
    $frame->writeRgba8(random_bytes($w * $h * 4), $w, $h);

    return $frame;
}

/** The same frame twice: one to work on through tickets, one through the read-back. */
function ticket_pair(Closure $driver, FormatSpec $format, int $w, int $h): array
{
    $a = new FullFramebuffer($driver(), $format, $w, $h);
    $a->writeRgba8(random_bytes($w * $h * 4), $w, $h);
    $b = new FullFramebuffer($driver(), $format, $w, $h);
    $b->blitFrom(new WatchedFrame($a, hidden: true));

    return [$a, $b];
}

beforeEach(fn () => mt_srand(11));

it('blits a frame from its ticket to the bytes the read-back gives', function (Closure $from, FormatSpec $sf, Closure $to, FormatSpec $tf) {
    $source = new WatchedFrame(ticket_frame($from, $sf, 13, 9));
    [$a, $b] = ticket_pair($to, $tf, 20, 14);

    $a->blitFrom($source, 4, 3);
    $b->blitFrom(new WatchedFrame($source, hidden: true), 4, 3);

    expect($a->dump())->toBe($b->dump());
})->with('ticket stores')->with('ticket formats')->with('ticket stores')->with('ticket formats');

it('never reads the source back when its store takes the ticket', function (Closure $from, FormatSpec $sf, FormatSpec $tf) {
    $source = new WatchedFrame(ticket_frame($from, $sf, 13, 9));
    $target = ticket_frame(fn () => new CLandFramebufferDriver(), $tf, 20, 14);

    $target->blitFrom($source, 2, 1);
    $target->paintImage($source, Affine::translation(3.5, 2.25)->multiply(Affine::rotation(0.4))->multiply(Affine::scaling(1.3, 1.1)), 200, Filter::LINEAR);

    // a C source lends its address and ext-fb reads it where it is; a PHP-land source lends none
    // and is read back once per operation
    expect($source->reads)->toBe(is_null($source->memory()) ? 2 : 0);
})->skip(! extension_loaded('fb'), 'ext-fb is not loaded')->with('ticket stores')->with('ticket formats')->with('ticket formats');

it('paints a frame from its ticket to the bytes the read-back gives', function (Closure $from, FormatSpec $sf, Closure $to, FormatSpec $tf, Filter $filter) {
    $source = new WatchedFrame(ticket_frame($from, $sf, 9, 7));
    [$a, $b] = ticket_pair($to, $tf, 24, 20);
    $placement = Affine::translation(12, 3)->multiply(Affine::rotation(0.6))->multiply(Affine::scaling(1.8, 1.4));
    $coverage = pack('vvvC', 6, 4, 12, 180).pack('vvvC', 9, 2, 15, 255);

    $a->paintImage($source, $placement, 220, $filter, new Region(2, 2, 18, 15), 0xFFD0A0FF, [0.5, 0.5, 8.5, 6.5], $coverage);
    $b->paintImage(new WatchedFrame($source, hidden: true), $placement, 220, $filter, new Region(2, 2, 18, 15), 0xFFD0A0FF, [0.5, 0.5, 8.5, 6.5], $coverage);

    expect($a->dump())->toBe($b->dump());
})->with('ticket stores')->with('ticket formats')->with('ticket stores')->with(['rgba8' => [FormatSpec::rgba8()], 'rgb565' => [new FormatSpec(PixelFormat::ROW_MAJOR, BitDepth::B16)]])->with([Filter::NEAREST, Filter::LINEAR]);

it('blits a frame onto itself, overlap included, as the read-back does', function (Closure $driver, FormatSpec $format, int $dx, int $dy) {
    [$a, $b] = ticket_pair($driver, $format, 16, 12);

    $a->blitFrom($a, $dx, $dy);
    $b->blitFrom(new WatchedFrame($b, hidden: true), $dx, $dy);

    expect($a->dump())->toBe($b->dump());
})->with('ticket stores')->with('ticket formats')->with([[2, 1], [-3, -2], [0, 3]]);

it('scrolls in its own library to the bytes the read-back gives', function (Closure $driver, FormatSpec $format, int $dx, int $dy) {
    [$a, $b] = ticket_pair($driver, $format, 16, 12);
    $clip = new Region(2, 1, 12, 9);

    $a->scroll($clip, $dx, $dy);
    // the reference: the pixels whose destination lies in the region, read back as RGBA8, landed shifted
    $from = $clip->intersect(new Region($clip->x - $dx, $clip->y - $dy, $clip->width, $clip->height));
    $b->writeRgba8($b->flushRegion($from, FormatSpec::rgba8()), $from->width, $from->height, $from->x + $dx, $from->y + $dy);

    expect($a->dump())->toBe($b->dump());
})->with('ticket stores')->with('ticket formats')->with([[2, 1], [-3, -2], [0, 3], [4, 0]]);

it('reads a ring through its front buffer, by pointer', function () {
    $ring = (new CLandFramebufferDriver('ring', frames: 2))->make(FormatSpec::rgba8(), 8, 6);
    $front = random_bytes(8 * 6 * 4);
    $ring->writeRgba8($front, 8, 6);
    $ring->present();
    $ring->writeRgba8(random_bytes(8 * 6 * 4), 8, 6);   // the back buffer, not shown

    $target = new FullFramebuffer(new CLandFramebufferDriver(), FormatSpec::rgba8(), 8, 6);
    $target->blitFrom($ring);

    expect($target->toRgba8())->toBe($ring->toRgba8())
        ->and($target->toRgba8())->toBe($front);
})->skip(! extension_loaded('fb'), 'ext-fb is not loaded');
