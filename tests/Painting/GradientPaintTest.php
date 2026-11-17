<?php

declare(strict_types=1);

use Jovian\Engines\Velvet\Lustrous\Framebuffers\PHPLandFramebufferDriver;
use Surface\Contracts\Graphics\StrokeStyle;
use Surface\Contracts\Rendering\Framebuffers\FormatSpec;
use Surface\Contracts\Rendering\Painting\Painter;
use Surface\Contracts\Rendering\Rasterization\Edges;
use Surface\Contracts\Rendering\Rasterization\RasterizeDriver;
use Surface\NutsAndBolts\Affine;
use Surface\NutsAndBolts\Color;
use Surface\NutsAndBolts\Gradient;
use Surface\NutsAndBolts\GradientSpread;
use Surface\NutsAndBolts\LinearGradient;
use Surface\NutsAndBolts\Path;
use Surface\NutsAndBolts\RadialGradient;
use VelvetGE\Painting\VelvetPainter;

/*
 * A gradient is baked into a ramp of 256 colours; a point picks the entry floor(t × 255 + ½) of its position t along
 * the gradient, spread applied. Velvet paints it through the path's coverage.
 */

function blackToWhite(float $x0 = 0, float $x1 = 255, GradientSpread $spread = GradientSpread::PAD): LinearGradient
{
    return new LinearGradient($x0, 0, $x1, 0, [[0.0, Color::rgb(0, 0, 0)], [1.0, Color::rgb(255, 255, 255)]], $spread);
}

function squareOf(float $x, float $y, float $side): Path
{
    return (new Path())->moveTo($x, $y)->lineTo($x + $side, $y)->lineTo($x + $side, $y + $side)->lineTo($x, $y + $side)->close();
}

it('records a gradient fill as its own command, with the ramp and the way back to the gradient', function (): void {
    $commands = (new RecordingEngine())->commandsOf(fn (Painter $g) => $g->translate(4, 0)->fill(squareOf(0, 0, 10), blackToWhite(0, 10)));

    expect($commands[0][0])->toBe('gradpath')
        ->and($commands[0][3]['kind'])->toBe('linear')
        ->and($commands[0][3]['spread'])->toBe(GradientSpread::PAD)
        ->and(strlen($commands[0][3]['ramp']))->toBe(1024)
        ->and($commands[0][3]['inverse']->apply(14.0, 0.0)[0])->toEqualWithDelta(1.0, 1e-9);
});

it('records a gradient stroke as its outline', function (): void {
    $line = (new Path())->moveTo(0, 5)->lineTo(20, 5);
    $commands = (new RecordingEngine())->commandsOf(fn (Painter $g) => $g->stroke($line, blackToWhite(0, 20), new StrokeStyle(width: 4.0)));

    expect($commands[0][0])->toBe('gradpath')
        ->and($commands[0][1])->toHaveCount(1);
});

function shaded(RasterizeDriver $raster, Closure $draw, int $width = 16, int $height = 4): \Jovian\Engines\Velvet\Lustrous\Framebuffers\StoreFramebuffer
{
    $buffer = (new PHPLandFramebufferDriver())->full(FormatSpec::rgba8(), $width, $height);
    (new VelvetPainter($buffer, $raster, Edges::HARD))->frame($draw);

    return $buffer;
}

it('paints a linear gradient across a path: each pixel the ramp entry of its centre', function (RasterizeDriver $raster): void {
    $drawn = shaded($raster, fn (Painter $g) => $g->fill(squareOf(0, 0, 4), blackToWhite(0, 4)), 4, 4);

    // t at the centres 0.5, 1.5, 2.5, 3.5 over 4 pixels: 0.125, 0.375, 0.625, 0.875 -> entries 32, 96, 159, 223
    expect(dechex($drawn->getPixel(0, 1)))->toBe('202020ff')
        ->and(dechex($drawn->getPixel(1, 1)))->toBe('606060ff')
        ->and(dechex($drawn->getPixel(2, 1)))->toBe('9f9f9fff')
        ->and(dechex($drawn->getPixel(3, 1)))->toBe('dfdfdfff')
        ->and($drawn->getPixel(0, 0))->toBe($drawn->getPixel(0, 3));
})->with('rasterization drivers');

it('paints a radial gradient out from its centre, and a gradient stroke along its outline', function (RasterizeDriver $raster): void {
    $radial = new RadialGradient(8, 8, 8, [[0.0, Color::rgb(255, 0, 0)], [1.0, Color::rgb(0, 0, 255)]]);
    $disc = shaded($raster, fn (Painter $g) => $g->fill(squareOf(0, 0, 16), $radial), 16, 16);
    $line = shaded($raster, fn (Painter $g) => $g->stroke((new Path())->moveTo(0, 2)->lineTo(16, 2), blackToWhite(0, 16), new StrokeStyle(width: 2.0)));

    // red at the centre, bluer toward the corner; the stroke runs dark to light, nothing beside it
    $middle = $disc->getPixel(8, 8);
    $corner = $disc->getPixel(0, 0);
    expect(($middle >> 24) & 0xFF)->toBeGreaterThan(230)
        ->and(($corner >> 8) & 0xFF)->toBeGreaterThan(230)
        ->and(($line->getPixel(1, 2) >> 24) & 0xFF)->toBeLessThan(($line->getPixel(14, 2) >> 24) & 0xFF)
        ->and($line->getPixel(8, 0))->toBe(0x00000000);
})->with('rasterization drivers');

it('lets a see-through gradient show what is under it', function (RasterizeDriver $raster): void {
    $clear = new LinearGradient(0, 0, 4, 0, [[0.0, Color::rgba(255, 0, 0, 0.0)], [1.0, Color::rgba(255, 0, 0, 1.0)]]);
    $drawn = shaded($raster, fn (Painter $g) => $g->clear(Color::rgb(0, 0, 255))->fill(squareOf(0, 0, 4), $clear), 4, 4);

    expect(($drawn->getPixel(0, 1) >> 8) & 0xFF)->toBeGreaterThan(200)   // still mostly blue
        ->and(($drawn->getPixel(3, 1) >> 24) & 0xFF)->toBeGreaterThan(200); // mostly red
})->with('rasterization drivers');

it('repeats and reflects past the ends', function (RasterizeDriver $raster): void {
    $repeat = shaded($raster, fn (Painter $g) => $g->fill(squareOf(0, 0, 8), blackToWhite(0, 4, GradientSpread::REPEAT)), 8, 8);
    $reflect = shaded($raster, fn (Painter $g) => $g->fill(squareOf(0, 0, 8), blackToWhite(0, 4, GradientSpread::REFLECT)), 8, 8);

    expect($repeat->getPixel(4, 1))->toBe($repeat->getPixel(0, 1))
        ->and($reflect->getPixel(4, 1))->toBe($reflect->getPixel(3, 1));
})->with('rasterization drivers');
