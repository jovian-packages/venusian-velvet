<?php

declare(strict_types=1);

use Jovian\Engines\Velvet\Lustrous\Rasterization\PHPLandRasterizationDriver;
use Surface\Contracts\Rendering\Framebuffers\FormatSpec;
use Surface\Contracts\Rendering\Painting\Painter;
use Surface\NutsAndBolts\Color;
use VelvetGE\Painting\VelvetPainter;

/*
 * A frame that changed in a few places is redrawn in those places. One that changed in many is
 * redrawn once, over the box that holds them all: every command is carried out once a region, so
 * many small regions cost more than their box.
 */

/** $count small squares on a 400x300 frame, the first $moving of them $shift pixels to the right. */
function scatteredSquares(int $count, int $moving, int $shift): Closure
{
    return function (Painter $g) use ($count, $moving, $shift): void {
        $g->clear(Color::rgb(0, 0, 0));
        for ($i = 0; $i < $count; $i++) {
            // 10 columns, 40 pixels apart both ways: no two squares' boxes touch
            $g->fillRect(10 + ($i % 10) * 40 + ($i < $moving ? $shift : 0), 10 + intdiv($i, 10) * 40, 6, 6, Color::rgb(255, 255, 255));
        }
    };
}

it('redraws a frame that changed in a few places in those places', function (): void {
    $painter = new VelvetPainter(nativeFull(FormatSpec::rgba8(), 400, 300), new PHPLandRasterizationDriver());
    $painter->frame(scatteredSquares(60, 3, 0));
    $painter->frame(scatteredSquares(60, 3, 4));

    expect($painter->damage())->toHaveCount(3);
});

it('redraws a frame that changed in many places once, over the box that holds them all', function (): void {
    $target = nativeFull(FormatSpec::rgba8(), 400, 300);
    $painter = new VelvetPainter($target, new PHPLandRasterizationDriver());
    $painter->frame(scatteredSquares(60, 60, 0));
    $painter->frame(scatteredSquares(60, 60, 4));

    // 60 squares moved: one region, holding their old and new places from (10, 10) to the last square's far corner (380, 216)
    $damage = $painter->damage();
    expect($damage)->toHaveCount(1)
        ->and($damage[0]->x)->toBeLessThanOrEqual(10)
        ->and($damage[0]->y)->toBeLessThanOrEqual(10)
        ->and($damage[0]->right())->toBeGreaterThanOrEqual(380)
        ->and($damage[0]->bottom())->toBeGreaterThanOrEqual(216);

    // and what is there is the frame, as a painter that drew it whole leaves it
    $whole = nativeFull(FormatSpec::rgba8(), 400, 300);
    (new VelvetPainter($whole, new PHPLandRasterizationDriver()))->frame(scatteredSquares(60, 60, 4));
    expect($target->toRgba8())->toBe($whole->toRgba8());
});
