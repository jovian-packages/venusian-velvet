<?php

declare(strict_types=1);

use Jovian\Engines\Velvet\Lustrous\Rasterization\PHPLandScanner;
use Jovian\Engines\Velvet\Lustrous\Stroking\CLandStroker;
use Jovian\Engines\Velvet\Lustrous\Stroking\PHPStroker;
use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\Contracts\Rendering\Framebuffers\Spans;
use Surface\Contracts\Rendering\Rasterization\Edges;
use Surface\Contracts\Rendering\Rasterization\FillRule;
use Surface\Contracts\Rendering\Rasterization\StrokeCap;
use Surface\Contracts\Rendering\Rasterization\StrokeJoin;
use Tests\Fixtures\PiecewiseStroker;

require_once __DIR__.'/../Fixtures/PiecewiseStroker.php';

/*
 * A stroker answers a stroke's outline: one contour for an open polyline, two for a closed. What that
 * outline covers is what the stroke's pieces cover (a quad a segment, a piece a join, a piece a cap):
 * the same pixels, the same coverage in each, for every join and cap.
 */

const OUTLINE_W = 160;
const OUTLINE_H = 120;

/** Pixel => coverage of the contours, filled non-zero and anti-aliased. */
function outlineCoverage(array $contours): array
{
    $grid = [];
    if ($contours === []) {
        return $grid;
    }
    $scanner = new PHPLandScanner(new Region(0, 0, OUTLINE_W, OUTLINE_H), Edges::ANTIALIASED);
    foreach (Spans::unpack($scanner->path($contours, FillRule::NON_ZERO)) as [$y, $x, $length, $coverage]) {
        for ($i = 0; $i < $length; $i++) {
            $grid[$y * OUTLINE_W + $x + $i] = $coverage;
        }
    }

    return $grid;
}

/** A seeded polyline: points on a turning arc (every corner turns the same way), or anywhere (crossing itself). */
function outlinePolyline(bool $jagged, bool $closed): array
{
    $between = fn (float $low, float $high): float => $low + ($high - $low) * mt_rand() / mt_getrandmax();
    $n = mt_rand(2, 9);
    $points = [];
    if ($jagged) {
        for ($i = 0; $i < $n; $i++) {
            $points[] = [$between(15, 145), $between(15, 105)];
        }

        return $points;
    }
    [$radius, $from, $sweep] = [$between(20, 45), $between(0, 6.28), $between(1.5, $closed ? 6.0 : 5.0)];
    for ($i = 0; $i < $n; $i++) {
        $angle = $from + $sweep * $i / $n;
        $points[] = [80 + $radius * cos($angle) * 1.5, 60 + $radius * sin($angle)];
    }

    return $points;
}

it('covers what the stroke\'s pieces cover, pixel for pixel', function (string $stroker, bool $jagged, bool $closed, StrokeJoin $join, StrokeCap $cap): void {
    if ($stroker === CLandStroker::class && ! class_exists(RasterStroker::class, false)) {
        $this->markTestSkipped('ext-rasterize is not loaded in this PHP.');
    }
    mt_srand(20261115);
    [$pieces, $outline] = [new PiecewiseStroker(), new $stroker()];

    $contours = 0;
    for ($trial = 0; $trial < 25; $trial++) {
        $points = outlinePolyline($jagged, $closed);
        $width = 1.0 + 11.0 * mt_rand() / mt_getrandmax();
        $answer = $outline->outline($points, $width, $closed, $cap, $join);
        $contours += count($answer);

        expect(outlineCoverage($answer))->toBe(outlineCoverage($pieces->outline($points, $width, $closed, $cap, $join)));
    }
    // one contour a stroke; two for a closed one of more than two points
    expect($contours)->toBeLessThanOrEqual($closed ? 50 : 25);
})->with([PHPStroker::class, CLandStroker::class])
    ->with(['turning one way' => false, 'crossing itself' => true])
    ->with(['open' => false, 'closed' => true])
    ->with(StrokeJoin::cases())
    ->with(StrokeCap::cases());

it('can tell a stroke that is not the pieces\' from one that is', function (): void {
    $points = [[20.0, 20.0], [100.0, 30.0], [60.0, 90.0]];

    // the check above is not blind: a stroke a twentieth wider covers other pixels
    expect(outlineCoverage((new PHPStroker())->outline($points, 6.3, false)))
        ->not->toBe(outlineCoverage((new PiecewiseStroker())->outline($points, 6.0, false)));
});
