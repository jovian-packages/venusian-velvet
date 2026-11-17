<?php

declare(strict_types=1);

use Jovian\Engines\Velvet\Lustrous\Stroking\CLandStroker;
use Jovian\Engines\Velvet\Lustrous\Stroking\PHPStroker;
use Jovian\Engines\Velvet\Providers\VenusianVelvetServiceProvider;
use Surface\Contracts\Graphics\StrokeStyle;
use Surface\Contracts\Rendering\Framebuffers\FormatSpec;
use Surface\Contracts\Rendering\Rasterization\StrokeCap;
use Surface\Contracts\Rendering\Rasterization\StrokeGeometry;
use Surface\Contracts\Rendering\Rasterization\StrokeJoin;
use Surface\NutsAndBolts\Color;
use Surface\NutsAndBolts\Path;
use Surface\Rendering\Stroker;

it('registers lustrous-php, lustrous-c where ext-rasterize can stroke, and an auto that takes lustrous-c when it can and lustrous-php otherwise', function (): void {
    $drivers = VenusianVelvetServiceProvider::strokingDrivers();
    $c = class_exists(RasterStroker::class, false);

    expect(array_keys($drivers))->toBe($c ? ['lustrous-php', 'lustrous-c', 'auto'] : ['lustrous-php', 'auto'])
        ->and($drivers['lustrous-php']()->driver())->toBe('lustrous-php')
        ->and($drivers['lustrous-php']()->stroker())->toBeInstanceOf(PHPStroker::class)
        ->and($drivers['auto']()->driver())->toBe($c ? 'lustrous-c' : 'lustrous-php');
    if ($c) {
        expect($drivers['lustrous-c']()->stroker())->toBeInstanceOf(CLandStroker::class);
    }
});

it('hands a dashed stroke back undrawn when the stroker in use cannot cut dashes, and draws the rest', function (): void {
    $before = Stroker::geometry();
    $solidOnly = new class implements StrokeGeometry {
        public function outline(array $points, float $stroke, bool $closed, StrokeCap $cap = StrokeCap::BUTT, StrokeJoin $join = StrokeJoin::MITER, float $miterLimit = self::MITER_LIMIT, ?array $direction = null): array
        {
            return (new PHPStroker())->outline($points, $stroke, $closed, $cap, $join, $miterLimit, $direction);
        }
    };
    $frame = nativeFull(FormatSpec::rgba8(), 64, 32);
    $painter = (new \Jovian\Engines\Velvet\Lustrous\Painting\PHPLandPaintbrushDriver(new \Jovian\Engines\Velvet\Lustrous\Rasterization\PHPLandRasterizationDriver(), true, new \Surface\Rendering\Recording\Drivers\PHPLandRecordingDriver()))->painter($frame);
    $red = Color::rgb(255, 0, 0);
    $line = fn (float $y): Path => (new Path())->moveTo(4, $y)->lineTo(60, $y);
    $dashed = new StrokeStyle(width: 4, dash: [6, 4]);

    try {
        Stroker::use($solidOnly);
        $painter->frame(function ($g) use ($red, $line, $dashed): void {
            $g->stroke($line(8), $red, new StrokeStyle(width: 4));
            $g->stroke($line(24), $red, $dashed);
        });
        $undrawn = $painter->undrawn();

        expect($frame->getPixel(30, 8))->not->toBe(0)
            ->and($frame->getPixel(7, 24))->toBe(0)
            ->and(count($undrawn))->toBe(1)
            ->and($undrawn[0][0])->toBe('stroke')
            ->and($undrawn[0][3])->toBe($dashed);

        // with a stroker that dashes, the same stroke is drawn and nothing comes back
        Stroker::use(new PHPStroker());
        $painter->frame(fn ($g) => $g->stroke($line(24), $red, $dashed));
        expect($frame->getPixel(7, 24))->not->toBe(0)
            ->and($frame->getPixel(12, 24))->toBe(0)
            ->and($painter->undrawn())->toBe([]);
    } finally {
        Stroker::use($before);
    }
});
