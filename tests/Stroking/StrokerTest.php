<?php

declare(strict_types=1);

use Surface\Contracts\Rendering\Rasterization\StrokeCap;
use Surface\Contracts\Rendering\Rasterization\StrokeJoin;
use Surface\Rendering\Stroker;

/** Twice the signed area: positive for the way round an open stroke's outline runs. */
function windingArea(array $flat): float
{
    $sum = 0.0;
    $n = intdiv(count($flat), 2);
    for ($i = 0; $i < $n; $i++) {
        $j = ($i + 1) % $n;
        $sum += $flat[2 * $i] * $flat[2 * $j + 1] - $flat[2 * $j] * $flat[2 * $i + 1];
    }

    return $sum;
}

it('outlines a segment as one quad straddling it', function (): void {
    expect(Stroker::outline([[0.0, 2.0], [6.0, 2.0]], 2.0, false))->toBe([[0.0, 1.0, 6.0, 1.0, 6.0, 3.0, 0.0, 3.0]]);
});

it('adds nothing but a point on each border where a polyline runs straight on', function (): void {
    expect(Stroker::outline([[0.0, 0.0], [4.0, 0.0], [8.0, 0.0]], 2.0, false))
        ->toBe([[0.0, -1.0, 4.0, -1.0, 8.0, -1.0, 8.0, 1.0, 4.0, 1.0, 0.0, 1.0]]);
});

it('outlines a right angle as one contour: the miter\'s tip outside, where the two edges cross inside', function (): void {
    // out along the right: (1, 0), the tip (6, 0), (6, 5); back along the left: (4, 5), the crossing (4, 2), (1, 2)
    expect(Stroker::outline([[1.0, 1.0], [5.0, 1.0], [5.0, 5.0]], 2.0, false))
        ->toBe([[1.0, 0.0, 6.0, 0.0, 6.0, 5.0, 4.0, 5.0, 4.0, 2.0, 1.0, 2.0]]);
});

it('still miters a 40° corner, whose tip sits under three half-strokes out', function (): void {
    // Interior angle 40°: the tip is 1 / sin(20°) ≈ 2.92 half-strokes from the corner.
    $corner = [[0.0, 0.0], [10.0, 0.0], [10.0 + 10 * cos(deg2rad(140)), 10 * sin(deg2rad(140))]];
    $mitered = Stroker::outline($corner, 2.0, false);
    $bevelled = Stroker::outline($corner, 2.0, false, StrokeCap::BUTT, StrokeJoin::BEVEL);

    // a miter is its tip, one point where a bevel is the two ends of the outside edges
    expect($mitered)->toHaveCount(1)
        ->and(count($mitered[0]))->toBe(count($bevelled[0]) - 2);
});

it('bevels a corner whose miter would reach past four half-strokes', function (): void {
    $hairpin = [[0.0, 0.0], [20.0, 1.0], [0.0, 2.0]];

    expect(Stroker::outline($hairpin, 2.0, false))->toBe(Stroker::outline($hairpin, 2.0, false, StrokeCap::BUTT, StrokeJoin::BEVEL));
});

it('outlines a closed polyline as its two borders, one each way round, every corner joined', function (): void {
    $contours = Stroker::outline([[0.0, 0.0], [10.0, 0.0], [10.0, 10.0]], 2.0, true);

    // three miter tips outside, three crossings inside: the closing corner as the others
    expect($contours)->toHaveCount(2)
        ->and($contours[0])->toHaveCount(6)
        ->and($contours[1])->toHaveCount(6)
        ->and(windingArea($contours[0]))->toBeGreaterThan(0.0)
        ->and(windingArea($contours[1]))->toBeLessThan(0.0);
});

it('turns every stroke the same way round, so strokes that overlap unite under non-zero', function (): void {
    $crossing = [[0.0, 0.0], [10.0, 3.0], [2.0, 9.0], [12.0, 12.0], [1.0, 1.0]];

    // wound one way everywhere, what a stroke's contours hold sums above 0: an open one's single contour, a closed one's two
    expect(Stroker::outline($crossing, 3.0, false))->toHaveCount(1)
        ->and(array_sum(array_map('windingArea', Stroker::outline($crossing, 3.0, false))))->toBeGreaterThan(0.0)
        ->and(Stroker::outline($crossing, 3.0, true))->toHaveCount(2)
        ->and(array_sum(array_map('windingArea', Stroker::outline($crossing, 3.0, true))))->toBeGreaterThan(0.0);
});

it('drops repeated points and draws nothing from fewer than two distinct ones', function (): void {
    expect(Stroker::outline([[1.0, 1.0], [1.0, 1.0], [4.0, 1.0], [4.0, 1.0]], 2.0, false))->toHaveCount(1)
        ->and(Stroker::outline([[1.0, 1.0], [1.0, 1.0]], 2.0, false))->toBe([])
        ->and(Stroker::outline([[1.0, 1.0]], 2.0, true))->toBe([])
        ->and(Stroker::outline([], 2.0, false))->toBe([]);
});

it('outlines a closed pair of points as the one segment between them', function (): void {
    expect(Stroker::outline([[0.0, 2.0], [6.0, 2.0]], 2.0, true))->toBe(Stroker::outline([[0.0, 2.0], [6.0, 2.0]], 2.0, false));
});
