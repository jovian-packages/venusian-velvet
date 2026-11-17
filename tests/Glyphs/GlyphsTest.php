<?php

declare(strict_types=1);

use Jovian\Engines\Velvet\Lustrous\Framebuffers\CLandFramebufferDriver;
use Jovian\Engines\Velvet\Lustrous\Framebuffers\PHPLandFramebufferDriver;
use Jovian\Engines\Velvet\Lustrous\Glyphs\AutoGlyphDriver;
use Jovian\Engines\Velvet\Lustrous\Glyphs\AutoGlyphs;
use Jovian\Engines\Velvet\Lustrous\Glyphs\CLandGlyphDriver;
use Jovian\Engines\Velvet\Lustrous\Glyphs\PHPLandGlyphDriver;
use Jovian\Engines\Velvet\Lustrous\Rasterization\PHPLandRasterizationDriver;
use Jovian\Engines\Velvet\Lustrous\Typography\PHPLandFontFaceDriver;
use Jovian\Engines\Velvet\Providers\VenusianVelvetServiceProvider;
use Jovian\Engines\Velvet\Tests\Fixtures\TinyFont;
use Surface\Contracts\Graphics\DrawingException;
use Surface\Contracts\Rendering\Framebuffers\FormatSpec;
use Surface\Contracts\Rendering\Framebuffers\InstanceThatManagesAFramebuffer;
use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\Contracts\Rendering\Glyphs\GlyphDriver;
use Surface\Contracts\Rendering\Rasterization\Edges;
use Surface\Contracts\Typography\FontFace;
use Surface\Contracts\Typography\FontMetrics;
use Surface\Contracts\Typography\FontSource;
use Surface\Contracts\Typography\GlyphRun;
use Surface\Contracts\Typography\TypesetLine;
use Surface\Contracts\Typography\TypesetText;
use Surface\NutsAndBolts\Affine;
use Surface\NutsAndBolts\Color;
use Surface\NutsAndBolts\Path;
use Surface\Rendering\Recording\DrawCommandQueue;
use Surface\Rendering\Recording\InkBoxes;
use Surface\Rendering\Recording\Queues\PHPLandQueue;
use Surface\Typography\Faces\FontFaceDriver;
use VelvetGE\Glyphs\GlyphCache;
use VelvetGE\Painting\VelvetPainter;

/*
 * Text drawn by Lustrous's glyphs through VelvetGE's glyph cache and painter. The font is written by
 * the test: glyph 1 is a box 500 units square in a face of 1000 units an em, so at size 20 it is 10
 * pixels square, its bottom on the baseline; glyph 0 has no outline.
 */

/** Faces opened by Lustrous's PHP reader, every outline asked for counted by source. */
final class CountingFaces extends FontFaceDriver
{
    /** @var array<string, int> */
    public array $asked = [];

    private PHPLandFontFaceDriver $reader;

    public function __construct()
    {
        $this->reader = new PHPLandFontFaceDriver();
    }

    public function driver(): string { return 'counting'; }

    public function face(FontSource $source): FontFace
    {
        // the reader opens the file's default instance; the source keeps its own key
        $face = $this->reader->face(new FontSource($source->path, $source->index));
        $asked = &$this->asked;

        return new class($face, $source, $asked) implements FontFace {
            public function __construct(private FontFace $face, private FontSource $source, private array &$asked) {}
            public function source(): FontSource { return $this->source; }
            public function unitsPerEm(): int { return $this->face->unitsPerEm(); }
            public function glyphCount(): int { return $this->face->glyphCount(); }
            public function metrics(float $size): FontMetrics { return $this->face->metrics($size); }
            public function glyph(int $codepoint): int { return $this->face->glyph($codepoint); }
            public function advance(int $glyph): int { return $this->face->advance($glyph); }
            public function outline(int $glyph): Path
            {
                $this->asked[$this->source->key()] = ($this->asked[$this->source->key()] ?? 0) + 1;

                return $this->face->outline($glyph);
            }
        };
    }

    public function release(): void { $this->reader->release(); }
}

beforeEach(function (): void {
    $font = new TinyFont();
    $font->simple(600, [[[0, 0, true], [500, 0, true], [500, 500, true], [0, 500, true]]]);
    $this->path = $font->write(sys_get_temp_dir().'/lustrous-glyphs-'.getmypid().'-'.bin2hex(random_bytes(4)).'.ttf');
    $this->source = new FontSource($this->path);
    $this->faces = new CountingFaces();
});

afterEach(function (): void {
    unlink($this->path);
});

/** One line of glyphs of one source, 12 pixels apart, the baseline 15 down. */
function glyphText(FontSource $source, array $glyphs = [1], float $size = 20.0): TypesetText
{
    $xs = array_map(fn (int $i): float => 12.0 * $i, array_keys($glyphs));
    $run = new GlyphRun($source, $size, $glyphs, $xs, array_fill(0, count($glyphs), 15.0));

    return new TypesetText([new TypesetLine(0.0, 15.0, 20.0, 0.0, 12.0 * count($glyphs), [$run])], 12.0 * count($glyphs), 20.0);
}

/** A 40 x 24 RGBA frame, its bytes in PHP or in C. */
function glyphFrame(string $where, string $kind = 'full'): InstanceThatManagesAFramebuffer
{
    $driver = $where === 'c' ? new CLandFramebufferDriver() : new PHPLandFramebufferDriver();

    return $driver->{$kind}(FormatSpec::rgba8(), 40, 24);
}

function glyphPainter(InstanceThatManagesAFramebuffer $frame, GlyphDriver $glyphs, FontFaceDriver $faces): VelvetPainter
{
    return new VelvetPainter($frame, new PHPLandRasterizationDriver(), null, true, null, new DrawCommandQueue(new PHPLandQueue(), new InkBoxes($faces)), $glyphs);
}

/** One frame: black, then $draw. */
function glyphFrameDrawn(VelvetPainter $painter, Closure $draw): void
{
    $painter->begin();
    $painter->clear(Color::rgb(0, 0, 0));
    $draw($painter);
    $painter->end();
}

/** The frame as rows: '#' opaque red, '.' black, '?' anything else. */
function glyphRows(InstanceThatManagesAFramebuffer $frame, int $from = 0, ?int $to = null): array
{
    $rows = [];
    for ($y = $from, $to ??= $frame->viewportHeight(); $y < $to; $y++) {
        $row = '';
        for ($x = 0; $x < $frame->viewportWidth(); $x++) {
            $row .= match ($frame->getPixel($x, $y)) { 0x000000FF => '.', 0xFF0000FF => '#', default => '?' };
        }
        $rows[] = $row;
    }

    return $rows;
}

/** A box of '#' from (x0, y0) to (x1, y1), excluded, on a 40-wide frame; rows $from..$to. */
function glyphBoxes(array $boxes, int $from = 0, int $to = 24): array
{
    $rows = array_fill(0, $to - $from, str_repeat('.', 40));
    foreach ($boxes as [$x0, $y0, $x1, $y1]) {
        for ($y = max($y0, $from); $y < min($y1, $to); $y++) {
            $rows[$y - $from] = substr_replace($rows[$y - $from], str_repeat('#', $x1 - $x0), $x0, $x1 - $x0);
        }
    }

    return $rows;
}

dataset('glyph modules', [
    'lustrous-php on a frame in PHP' => ['php', fn (FontFaceDriver $f) => new PHPLandGlyphDriver($f)],
    'lustrous-php on a frame in C' => ['c', fn (FontFaceDriver $f) => new PHPLandGlyphDriver($f)],
    'lustrous-c on a frame in C' => ['c', fn (FontFaceDriver $f) => new CLandGlyphDriver($f)],
    'auto on a frame in PHP' => ['php', fn (FontFaceDriver $f) => new AutoGlyphDriver($f)],
    'auto on a frame in C' => ['c', fn (FontFaceDriver $f) => new AutoGlyphDriver($f)],
]);

it('draws each glyph from its outline where the text puts it, and nothing for a glyph with no outline', function (string $where, Closure $module): void {
    $frame = glyphFrame($where);
    $painter = glyphPainter($frame, $module($this->faces), $this->faces);
    glyphFrameDrawn($painter, fn (VelvetPainter $p) => $p->text(glyphText($this->source, [1, 0, 1]), 3, 0, Color::rgb(255, 0, 0)));

    // glyph 1 at pen 0 and 24, moved by (3, 0): x 3..13 and 27..37, its bottom on the baseline at 15
    expect(glyphRows($frame))->toBe(glyphBoxes([[3, 5, 13, 15], [27, 5, 37, 15]]));
})->with('glyph modules');

it('puts each glyph\'s origin on the nearest whole pixel', function (string $where, Closure $module): void {
    $frame = glyphFrame($where);
    $painter = glyphPainter($frame, $module($this->faces), $this->faces);
    glyphFrameDrawn($painter, function (VelvetPainter $p): void {
        $p->text(glyphText($this->source), 3.4, -2.6, Color::rgb(255, 0, 0));
        $p->text(glyphText($this->source), 20.5, 6.49, Color::rgb(255, 0, 0));
    });

    // (3.4, 12.4) lands on (3, 12); (20.5, 21.49) on (21, 21)
    expect(glyphRows($frame))->toBe(glyphBoxes([[3, 2, 13, 12], [21, 11, 31, 21]]));
})->with('glyph modules');

it('draws glyphs at the size the transform makes them, turned with it', function (string $where, Closure $module): void {
    $frame = glyphFrame($where);
    $painter = glyphPainter($frame, $module($this->faces), $this->faces);
    glyphFrameDrawn($painter, function (VelvetPainter $p): void {
        $p->push();
        $p->scale(0.5);
        $p->text(glyphText($this->source), 0, 0, Color::rgb(255, 0, 0));
        $p->pop();
        // a quarter turn: (x, y) lands at (-y, x), then moved to (40, 4)
        $p->transform(new Affine(0, 1, -1, 0, 40, 4));
        $p->text(glyphText($this->source), 0, 0, Color::rgb(255, 0, 0));
    });

    // half size: 5 pixels square on the baseline at 7.5 (rounded to 8); turned: the box's x 0..10 runs down from 4 and its y 5..15 runs left from 35
    expect(glyphRows($frame))->toBe(glyphBoxes([[0, 3, 5, 8], [25, 4, 35, 14]]));
})->with('glyph modules');

it('draws nothing outside the clip', function (string $where, Closure $module): void {
    $frame = glyphFrame($where);
    $painter = glyphPainter($frame, $module($this->faces), $this->faces);
    glyphFrameDrawn($painter, function (VelvetPainter $p): void {
        $p->clip(new Region(6, 0, 30, 9));
        $p->text(glyphText($this->source, [1, 1]), 3, 0, Color::rgb(255, 0, 0));
    });

    expect(glyphRows($frame))->toBe(glyphBoxes([[6, 5, 13, 9], [15, 5, 25, 9]]));
})->with('glyph modules');

it('draws text into a paged frame a page at a time, each page only its rows', function (Closure $module): void {
    $paged = (new PHPLandFramebufferDriver())->paged(FormatSpec::rgba8(), 40, 24, 8);
    $whole = glyphFrame('php');
    $painter = glyphPainter($paged, $module($this->faces), $this->faces);
    $draw = function (VelvetPainter $p): void {
        $p->clear(Color::rgb(0, 0, 0));
        $p->text(glyphText($this->source, [1, 1]), 3, 0, Color::rgb(255, 0, 0));
    };
    $pages = '';
    for ($page = 0; $page < $paged->pages(); $page++) {
        $paged->setPage($page);
        $page === 0 ? $painter->frame($draw) : $painter->replay();
        $pages .= $paged->dump();
    }
    // the same boxes filled as rects, page boundaries at 8 and 16 crossing them
    (new VelvetPainter($whole, new PHPLandRasterizationDriver()))->frame(fn (VelvetPainter $p) => $p->clear(Color::rgb(0, 0, 0))->fillRect(3, 5, 10, 10, Color::rgb(255, 0, 0))->fillRect(15, 5, 10, 10, Color::rgb(255, 0, 0)));

    expect(bin2hex($pages))->toBe(bin2hex($whole->dump()));
})->with([
    'lustrous-php' => [fn (FontFaceDriver $f) => new PHPLandGlyphDriver($f)],
    'auto' => [fn (FontFaceDriver $f) => new AutoGlyphDriver($f)],
]);

it('asks the face for a glyph once a size, and again for each other size and each other source', function (): void {
    $glyphs = (new PHPLandGlyphDriver($this->faces))->glyphs(Edges::ANTIALIASED);
    $frame = glyphFrame('php');
    $command = fn (FontSource $source, float $size, float $scale): array => ['glyphs', glyphText($source, [1, 1, 1], $size)->runs(), [$scale, 0.0, 0.0, $scale, 0.0, 0.0], 0xFF0000FF, [0.0, 0.0, 40.0, 24.0], new Region(0, 0, 40, 24)];
    $wide = new FontSource($this->path, 0, ['wght' => 700]);

    $glyphs->draw($command($this->source, 20.0, 1.0), new Region(0, 0, 40, 24), $frame);
    $glyphs->draw($command($this->source, 20.0, 1.0), new Region(0, 0, 40, 24), $frame);
    // 10 at twice the scale is 20 pixels: the glyph already kept
    $glyphs->draw($command($this->source, 10.0, 2.0), new Region(0, 0, 40, 24), $frame);
    expect($this->faces->asked)->toBe([$this->source->key() => 1])
        ->and($glyphs->count())->toBe(1);

    $glyphs->draw($command($this->source, 12.0, 1.0), new Region(0, 0, 40, 24), $frame);
    $glyphs->draw($command($wide, 20.0, 1.0), new Region(0, 0, 40, 24), $frame);
    expect($this->faces->asked)->toBe([$this->source->key() => 2, $wide->key() => 1])
        ->and($glyphs->count())->toBe(3);
});

it('drops the faces and sizes drawn longest ago when its glyphs pass the budget, never those of the draw', function (string $store): void {
    // hard-edged, a 10 x 10 glyph keeps 100 bytes as a mask or 70 as ten spans; 11 x 11, 121 or 77: either two pass 100
    $driver = $store === 'c' ? new CLandGlyphDriver($this->faces) : new PHPLandGlyphDriver($this->faces);
    $frame = glyphFrame($store);
    $glyphs = new GlyphCache((fn () => $this->store(Edges::HARD))->call($driver), $this->faces, budget: 100);
    $draw = fn (float $size) => $glyphs->draw(['glyphs', glyphText($this->source, [1], $size)->runs(), [1.0, 0.0, 0.0, 1.0, 0.0, 0.0], 0xFF0000FF, [0.0, 0.0, 40.0, 24.0], new Region(0, 0, 40, 24)], new Region(0, 0, 40, 24), $frame);

    $draw(20.0);
    $draw(22.0);
    expect($glyphs->count())->toBe(1);   // 20 dropped

    $draw(20.0);
    expect($this->faces->asked[$this->source->key()])->toBe(3)
        ->and($glyphs->store()->size())->toBeLessThanOrEqual(100);

    // one draw that needs more than the budget keeps all it drew
    $glyphs->draw(['glyphs', [...glyphText($this->source, [1], 30.0)->runs(), ...glyphText($this->source, [1], 32.0)->runs()], [1.0, 0.0, 0.0, 1.0, 0.0, 0.0], 0xFF0000FF, [0.0, 0.0, 40.0, 24.0], new Region(0, 0, 40, 24)], new Region(0, 0, 40, 24), $frame);
    expect($glyphs->count())->toBe(2);
})->with(['php', 'c']);

it('keeps glyphs in C for a frame in C and in PHP for a frame in PHP, under auto', function (): void {
    /** @var AutoGlyphs $auto */
    $auto = (new AutoGlyphDriver($this->faces))->glyphs(Edges::ANTIALIASED);
    $command = ['glyphs', glyphText($this->source)->runs(), [1.0, 0.0, 0.0, 1.0, 0.0, 0.0], 0xFF0000FF, [0.0, 0.0, 40.0, 24.0], new Region(0, 0, 40, 24)];

    $auto->draw($command, new Region(0, 0, 40, 24), glyphFrame('c'));
    expect([$auto->c->count(), $auto->php->count()])->toBe([1, 0]);

    $auto->draw($command, new Region(0, 0, 40, 24), glyphFrame('php'));
    expect([$auto->c->count(), $auto->php->count()])->toBe([1, 1]);
});

it('refuses to draw C glyphs into a frame whose pixels PHP holds', function (): void {
    $painter = glyphPainter(glyphFrame('php'), new CLandGlyphDriver($this->faces), $this->faces);

    expect(fn () => glyphFrameDrawn($painter, fn (VelvetPainter $p) => $p->text(glyphText($this->source), 3, 0, Color::rgb(255, 0, 0))))
        ->toThrow(DrawingException::class, 'does not draw into this framebuffer');
});

it('tells a frame in C what the C glyphs wrote into its memory', function (): void {
    $frame = glyphFrame('c', 'dirty');
    $painter = glyphPainter($frame, new CLandGlyphDriver($this->faces), $this->faces);
    $frame->beginEpoch();
    $painter->begin();
    $painter->text(glyphText($this->source), 3, 0, Color::rgb(255, 0, 0));
    $painter->end();

    expect($frame->written())->toEqual([new Region(3, 5, 10, 10)]);
});

it('registers lustrous-php and auto everywhere, and lustrous-c where ext-rasterize keeps glyphs and ext-fb is loaded', function (): void {
    $c = class_exists(RasterSpanStore::class) && RasterScanner::fills();

    expect(array_keys(VenusianVelvetServiceProvider::glyphDrivers()))->toBe($c ? ['lustrous-php', 'lustrous-c', 'auto'] : ['lustrous-php', 'auto']);
});
