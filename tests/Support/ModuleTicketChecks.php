<?php

use Jovian\Engines\Velvet\Lustrous\Framebuffers\CLandFramebufferDriver;
use Jovian\Engines\Velvet\Lustrous\Framebuffers\FullFramebuffer;
use Jovian\Engines\Velvet\Lustrous\Framebuffers\RingFramebuffer;
use Surface\Contracts\Rendering\Framebuffers\Filter;
use Surface\Contracts\Rendering\Framebuffers\FormatSpec;
use Surface\Contracts\Rendering\Framebuffers\InstanceThatManagesAFramebuffer;
use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\NutsAndBolts\Affine;

/*
 * The hand-off rule for a framebuffer module, shared by every module's tests: library to library
 * is a pointer. When a source lends memory (here a Lustrous-C frame, and a ring through its front
 * buffer), the module's blitFrom() and paintImage() read it where it is, never through a read-back,
 * and land the same bytes the read-back path does.
 *
 * $make(FormatSpec $format, int $width, int $height): the module's frame.
 * $formats: the formats to try sources in (the module reads any memory it can hold in place).
 * $byPointer: which of blitFrom and paintImage the module's library can do from memory; the others
 * are held only to giving the same bytes (a library with no copy operation reads back to blit).
 */
function moduleTicketChecks(string $module, Closure $make, FormatSpec $target, array $formats, array $byPointer = ['blitFrom', 'paintImage']): void
{
    $sources = function (FormatSpec $format): array {
        $full = new FullFramebuffer(new CLandFramebufferDriver(), $format, 12, 9);
        $full->writeRgba8(random_bytes(12 * 9 * 4), 12, 9);
        $ring = new RingFramebuffer(new CLandFramebufferDriver(), $format, 12, 9, 2);
        $ring->writeRgba8(random_bytes(12 * 9 * 4), 12, 9);
        $ring->present();
        $ring->writeRgba8(random_bytes(12 * 9 * 4), 12, 9); // the back buffer differs from the front

        return ['lustrous-c' => $full, 'ring' => $ring];
    };
    $pair = function () use ($make, $target): array {
        $a = $make($target, 20, 14);
        $a->writeRgba8(random_bytes(20 * 14 * 4), 20, 14);
        $b = $make($target, 20, 14);
        $b->writeRgba8($a->toRgba8(), 20, 14);

        return [$a, $b];
    };
    $placement = Affine::translation(3.5, 2.25)->multiply(Affine::rotation(0.4))->multiply(Affine::scaling(1.3, 1.1));

    foreach ($formats as $name => $format) {
        it("{$module}: ".implode(' and ', $byPointer)." a {$name} C source from its memory, never reading it back", function () use ($sources, $pair, $format, $placement, $byPointer) {
            mt_srand(11);
            foreach ($sources($format) as $kind => $frame) {
                // The frame itself does the work, so a ring is seen as a ring. Its reads are
                // counted on the same calls through a watched copy of what it lends: a wrapper
                // around a ring would answer its back buffer, so a ring is watched at its front.
                $watched = new WatchedFrame($frame instanceof RingFramebuffer ? $frame->front() : $frame);
                [$a, $b] = $pair();
                $a->blitFrom($frame, 4, 3);
                $b->blitFrom(new WatchedFrame($frame, hidden: true), 4, 3);
                $a->paintImage($frame, $placement, 200, Filter::LINEAR);
                $b->paintImage(new WatchedFrame($frame, hidden: true), $placement, 200, Filter::LINEAR);
                [$c] = $pair();
                if (in_array('blitFrom', $byPointer, true)) {
                    $c->blitFrom($watched, 4, 3);
                }
                if (in_array('paintImage', $byPointer, true)) {
                    $c->paintImage($watched, $placement, 200, Filter::LINEAR);
                }

                expect($watched->reads)->toBe(0, "{$kind} source was read back")
                    ->and($a->toRgba8())->toBe($b->toRgba8(), "{$kind}: memory path and read-back differ");
            }
        });
    }

    it("{$module}: scrolls within its own pixels, as the read-back would", function () use ($make, $target) {
        mt_srand(12);
        $f = $make($target, 20, 14);
        $f->writeRgba8($before = random_bytes(20 * 14 * 4), 20, 14);
        $f->scroll(new Region(2, 1, 15, 11), 3, -2);
        $want = $make($target, 20, 14);
        $want->writeRgba8($before, 20, 14);
        $keep = $want->toRgba8();
        // the reference: pixels whose destination is inside the region move by (dx, dy)
        $out = $keep;
        for ($y = 1; $y < 12; $y++) {
            for ($x = 2; $x < 17; $x++) {
                [$sx, $sy] = [$x - 3, $y + 2];
                if ($sx >= 2 && $sx < 17 && $sy >= 1 && $sy < 12) {
                    $out = substr_replace($out, substr($keep, ($sy * 20 + $sx) * 4, 4), ($y * 20 + $x) * 4, 4);
                }
            }
        }
        expect($f->toRgba8())->toBe($out);
    });
}
