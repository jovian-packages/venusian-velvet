<?php

declare(strict_types=1);

use Jovian\Engines\Velvet\Lustrous\Rasterization\PHPLandRasterizationDriver;
use Jovian\Engines\Velvet\Tests\Fixtures\AntialiasedOnlyScanner;
use Jovian\Engines\Velvet\Tests\Fixtures\CoreOnlyScanner;
use Jovian\Engines\Velvet\Tests\Fixtures\PartialRasterizationDriver;
use Jovian\Engines\Velvet\Tests\Fixtures\ShapesAndBatchScanner;
use Surface\Contracts\Rendering\Framebuffers\FormatSpec;
use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\Contracts\Rendering\Rasterization\Edges;
use Venusian\Topside\Tests\Support\Rasterization\SpanGrid;

/*
 * Velvet's rasterizer lowers every shape to a question its own scanner answers, and asks no other
 * scanner. A scanner with the shape's own op gets it; one without gets a curved shape as an outline
 * through its path(); a shadow or a one-pixel line it has no op for is handed back undrawn.
 */

/** @return array<int, int> pixel (y * 65536 + x) => coverage */
function lowering_cells(string $spans): array
{
    $cells = [];
    foreach (Surface\Contracts\Rendering\Framebuffers\Spans::unpack($spans) as [$y, $x, $length, $coverage]) {
        for ($i = 0; $i < $length; $i++) {
            $cells[$y * 65536 + $x + $i] = $coverage;
        }
    }

    return $cells;
}

function lowering(string $scanner, Edges $edges = Edges::ANTIALIASED, Region $clip = new Region(0, 0, 120, 120)): VelvetGE\Rasterization\ScannerRasterizer
{
    return (new PartialRasterizationDriver('stand-in', $scanner))->rasterizer($clip, $edges);
}

/** One shape command of every kind, as a painter records them. */
function everyShape(Edges $edges): array
{
    $clip = new Region(0, 0, 120, 120);
    $thin = $edges === Edges::HARD ? 1.0 : 3.0;

    return [
        ['path', [[[4.5, 4.5], [60.25, 10.0], [30.0, 70.75]]], Surface\Contracts\Rendering\Rasterization\FillRule::NON_ZERO, 0xFF0000FF, $clip],
        ['ellipse', 60.0, 60.0, 30.5, 20.25, 0x00FF00FF, $clip],
        ['ring', 60.0, 60.0, 25.0, 18.0, 4.5, 0x0000FFFF, $clip],
        ['roundrect', 10.0, 12.0, 90.5, 70.0, [8.0, 3.0, 14.0, 0.0], 0xFFFF00FF, $clip],
        ['roundring', 10.0, 12.0, 90.5, 70.0, [8.0, 3.0, 14.0, 0.0], 3.0, 0x00FFFFFF, $clip],
        ['shadow', 20.0, 20.0, 80.0, 60.0, 6.0, 5.0, 0x000000FF, $clip],
        ['polyline', [[5.0, 100.0], [60.0, 90.0], [110.0, 110.0]], $thin, false, 0xFF00FFFF, $clip],
    ];
}

it('gives a scanner with shapes their own ops', function (): void {
    [CoreOnlyScanner::$asked, CoreOnlyScanner::$batched] = [[], []];
    lowering(ShapesAndBatchScanner::class)->fillEllipse(60.0, 60.0, 30.0, 20.0);
    lowering(ShapesAndBatchScanner::class)->fillRoundRect(10.0, 10.0, 80.0, 60.0, [5.0, 5.0, 5.0, 5.0]);

    expect(CoreOnlyScanner::$asked)->toBe(['batch', 'batch'])
        ->and(CoreOnlyScanner::$batched)->toBe([Surface\Contracts\Rendering\Rasterization\Scanner::ELLIPSE, Surface\Contracts\Rendering\Rasterization\Scanner::ROUND_RECT]);
});

it('gives a path-only scanner its curved shapes as outlines, and asks it nothing else', function (): void {
    CoreOnlyScanner::$asked = [];
    $r = lowering(CoreOnlyScanner::class);
    $r->fillEllipse(60.0, 60.0, 30.0, 20.0);
    $r->strokeEllipse(60.0, 60.0, 30.0, 20.0, 4.0);
    $r->fillRoundRect(10.0, 10.0, 80.0, 60.0, [5.0, 0.0, 9.0, 2.0]);
    $r->strokeRoundRect(10.0, 10.0, 80.0, 60.0, [5.0, 0.0, 9.0, 2.0], 3.0);

    expect(CoreOnlyScanner::$asked)->toBe(['path', 'path', 'path', 'path']);
});

/*
 * The outlines against Lustrous's formulas, measured 2026-11-15 over 60 random ellipses, rings,
 * round rects and round rings each: anti-aliased, every pixel within 16; hard, 0.15% of pixels in
 * or out at the edge, the rest the same.
 */
it('outlines curved shapes as close to the formulas as an outline gets', function (Edges $edges): void {
    $clip = new Region(0, 0, 120, 120);
    $outline = lowering(CoreOnlyScanner::class, $edges, $clip);
    $formula = (new PHPLandRasterizationDriver())->rasterizer($clip, $edges);
    $worst = $flipped = $all = 0;
    mt_srand(3);
    for ($i = 0; $i < 60; $i++) {
        [$cx, $cy, $rx, $ry, $s] = [mt_rand(200, 10000) / 100, mt_rand(200, 10000) / 100, mt_rand(100, 4000) / 100, mt_rand(100, 4000) / 100, mt_rand(50, 800) / 100];
        $radii = [mt_rand(0, 1500) / 100, mt_rand(0, 1500) / 100, mt_rand(0, 1500) / 100, mt_rand(0, 1500) / 100];
        foreach ([
            [$outline->fillEllipse($cx, $cy, $rx, $ry), $formula->fillEllipse($cx, $cy, $rx, $ry)],
            [$outline->strokeEllipse($cx, $cy, $rx, $ry, $s), $formula->strokeEllipse($cx, $cy, $rx, $ry, $s)],
            [$outline->fillRoundRect($cx - $rx, $cy - $ry, 2 * $rx, 2 * $ry, $radii), $formula->fillRoundRect($cx - $rx, $cy - $ry, 2 * $rx, 2 * $ry, $radii)],
            [$outline->strokeRoundRect($cx - $rx, $cy - $ry, 2 * $rx, 2 * $ry, $radii, $s), $formula->strokeRoundRect($cx - $rx, $cy - $ry, 2 * $rx, 2 * $ry, $radii, $s)],
        ] as [$a, $b]) {
            SpanGrid::assertWellFormed($a, $clip, $edges);
            $p = lowering_cells($a);
            $q = lowering_cells($b);
            foreach ($p + $q as $k => $_) {
                $d = abs(($p[$k] ?? 0) - ($q[$k] ?? 0));
                $worst = max($worst, $d);
                $flipped += $d > 0 ? 1 : 0;
                $all++;
            }
        }
    }

    $edges === Edges::ANTIALIASED
        ? expect($worst)->toBeLessThanOrEqual(16)
        : expect($flipped / $all)->toBeLessThan(0.002);
})->with([Edges::ANTIALIASED, Edges::HARD]);

it('hands back a shadow and a one-pixel line a scanner has no op for, undrawn', function (): void {
    $r = lowering(CoreOnlyScanner::class, Edges::HARD);

    expect($r->shadow(20.0, 20.0, 60.0, 40.0, 6.0, 5.0))->toBeNull()
        ->and($r->line(5.0, 5.0, 100.0, 40.0))->toBeNull()
        ->and($r->strokeRect(5.0, 5.0, 50.0, 40.0))->toBeNull()
        ->and($r->polyline([[5.0, 5.0], [60.0, 30.0]]))->toBeNull()
        // wider than a pixel, a line is an outline every scanner fills
        ->and($r->line(5.0, 5.0, 100.0, 40.0, 3.0))->not->toBe('');
});

it('takes its edges from the scanner: asked for hard edges, a scanner without them draws anti-aliased', function (): void {
    $asked = lowering(AntialiasedOnlyScanner::class, Edges::HARD);
    $soft = lowering(AntialiasedOnlyScanner::class, Edges::ANTIALIASED);

    expect($asked->edges())->toBe(Edges::ANTIALIASED)
        ->and(lowering(CoreOnlyScanner::class, Edges::HARD)->edges())->toBe(Edges::HARD)
        ->and($asked->fillEllipse(60.0, 60.0, 30.0, 20.0))->toBe($soft->fillEllipse(60.0, 60.0, 30.0, 20.0))
        // no one-pixel line without hard edges: a thin stroke is an outline the scanner fills
        ->and($asked->line(5.0, 5.0, 100.0, 40.0))->toBe($soft->line(5.0, 5.0, 100.0, 40.0))->not->toBeNull()->not->toBe('')
        ->and(min(lowering_cells($asked->line(5.0, 5.0, 100.0, 40.0))))->toBeLessThan(255);
});

it('draws what the scanner answers and hands back the rest of a batch, in order, as given', function (Edges $edges): void {
    $shapes = everyShape($edges);
    $frame = framebuffers()->driver('native')->make(FormatSpec::rgba8(), 120, 120);
    CoreOnlyScanner::$asked = [];

    $undrawn = lowering(CoreOnlyScanner::class, $edges)->fillBatch($shapes, $frame);

    $expected = [$shapes[5]];
    if ($edges === Edges::HARD) {
        $expected[] = $shapes[6];
    }
    expect($undrawn)->toBe($expected)
        ->and(CoreOnlyScanner::$asked)->toBe(array_fill(0, $edges === Edges::HARD ? 5 : 6, 'path'))
        ->and($frame->toRgba8())->not->toBe(str_repeat("\0", 120 * 120 * 4));
})->with([Edges::ANTIALIASED, Edges::HARD]);

it('hands nothing back from a scanner that has every op', function (Edges $edges): void {
    $frame = framebuffers()->driver('native')->make(FormatSpec::rgba8(), 120, 120);

    expect((new PHPLandRasterizationDriver())->rasterizer(new Region(0, 0, 120, 120), $edges)->fillBatch(everyShape($edges), $frame))->toBe([]);
})->with([Edges::ANTIALIASED, Edges::HARD]);

it('names the driver that minted a rasterizer', function (): void {
    expect(lowering(CoreOnlyScanner::class)->driver())->toBe('stand-in');
});
