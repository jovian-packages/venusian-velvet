<?php

declare(strict_types=1);

use Jovian\Engines\Velvet\Lustrous\Typography\OpenTypeFile;
use Jovian\Engines\Velvet\Tests\Fixtures\TinyFont;
use Surface\Contracts\Typography\FontSource;
use Surface\Contracts\Typography\TypographyException;

/*
 * Lustrous's faces, in PHP and in C, read font files this project did not write: each test writes a
 * font that holds known values and checks the face finds exactly those.
 */

beforeEach(function (): void {
    $this->directory = sys_get_temp_dir().'/lustrous-face-'.getmypid().'-'.bin2hex(random_bytes(4));
    mkdir($this->directory);
});

afterEach(function (): void {
    array_map('unlink', glob($this->directory.'/*'));
    rmdir($this->directory);
});

function faceOf(string $part, TinyFont $font, string $directory, string $name = 'face.ttf'): \Surface\Contracts\Typography\FontFace
{
    return new $part(new FontSource($font->write("{$directory}/{$name}")));
}

it('reads the em and the hhea metrics, scaled to a size', function (string $part): void {
    $font = new TinyFont();
    $font->unitsPerEm = 2048;
    $font->hhea = [1638, -410, 67];
    $face = faceOf($part, $font, $this->directory);
    $metrics = $face->metrics(20.48);

    expect($face->unitsPerEm())->toBe(2048)
        ->and($metrics->ascent)->toEqualWithDelta(16.38, 1e-9)
        ->and($metrics->descent)->toEqualWithDelta(4.10, 1e-9)
        ->and($metrics->lineGap)->toEqualWithDelta(0.67, 1e-9);
})->with('lustrous faces');

it('takes the OS/2 typographic metrics when the face sets USE_TYPO_METRICS, and when hhea gives none', function (string $part): void {
    $flagged = new TinyFont();
    $flagged->useTypoMetrics = true;
    $empty = new TinyFont();
    $empty->hhea = [0, 0, 0];

    foreach ([faceOf($part, $flagged, $this->directory, 'a.ttf'), faceOf($part, $empty, $this->directory, 'b.ttf')] as $face) {
        $metrics = $face->metrics(1000);
        expect([$metrics->ascent, $metrics->descent, $metrics->lineGap])->toEqual([700.0, 300.0, 50.0]);
    }
})->with('lustrous faces');

it('maps codepoints through format 4 segments, by delta and through the glyph array, and 0 for the rest', function (string $part): void {
    $font = new TinyFont();
    [$a, $b, $c] = [$font->simple(500, []), $font->simple(500, []), $font->simple(500, [])];
    $font->map(0x41, $a)->map(0x42, $b)->map(0x43, $c)   // consecutive glyphs: a delta
        ->map(0x61, $c)->map(0x62, $a);                    // out of order: the glyph array
    $face = faceOf($part, $font, $this->directory);

    expect(array_map(fn (int $cp) => $face->glyph($cp), [0x41, 0x42, 0x43, 0x61, 0x62, 0x44, 0x60, 0x1F600]))
        ->toBe([$a, $b, $c, $c, $a, 0, 0, 0]);
})->with('lustrous faces');

it('gives 0 for a codepoint the character map sends past the last glyph', function (string $part): void {
    $font = new TinyFont();
    $a = $font->simple(500, []);
    $font->map(0x41, $a)->map(0x42, 50);   // the face has 2 glyphs
    $face = faceOf($part, $font, $this->directory);

    expect([$face->glyph(0x41), $face->glyph(0x42)])->toBe([$a, 0]);
})->with('lustrous faces');

it('maps every plane through format 12, and prefers it to format 4', function (string $part): void {
    $font = new TinyFont();
    [$a, $b] = [$font->simple(500, []), $font->simple(500, [])];
    $font->map(0x41, $a, 4)->map(0x41, $b, 12)->map(0x1F600, $a, 12);
    $face = faceOf($part, $font, $this->directory);

    expect([$face->glyph(0x41), $face->glyph(0x1F600), $face->glyph(0x1F601)])->toBe([$b, $a, 0]);
})->with('lustrous faces');

it('gives a glyph past the long metrics the last advance, and an unknown glyph none', function (string $part): void {
    $font = new TinyFont();
    $font->simple(610, []);
    $font->simple(720, []);
    $font->simple(999, []);
    $font->longMetrics = 2;   // glyphs 0 and 1 have their own; 2 and 3 repeat glyph 1's
    $face = faceOf($part, $font, $this->directory);

    expect([$face->advance(0), $face->advance(1), $face->advance(2), $face->advance(3), $face->advance(4)])
        ->toBe([500, 610, 610, 610, 0]);
})->with('lustrous faces');

it('outlines a simple glyph: on-curve points as lines, off-curve ones as quadratic controls, an implied point between two', function (string $part): void {
    $font = new TinyFont();
    $glyph = $font->simple(600, [[[0, 0, true], [600, 0, true], [600, 700, false], [0, 700, false]]]);

    expect(faceOf($part, $font, $this->directory)->outline($glyph)->ops())->toEqual([
        ['M', 0.0, 0.0], ['L', 600.0, 0.0], ['Q', 600.0, 700.0, 300.0, 700.0], ['Q', 0.0, 700.0, 0.0, 0.0], ['Z'],
    ]);
})->with('lustrous faces');

it('starts a contour that begins off the curve at its last point, or halfway between its two ends when both are off', function (string $part): void {
    $font = new TinyFont();
    $lastOn = $font->simple(600, [[[100, 0, false], [200, 100, true], [0, 100, true]]]);
    $bothOff = $font->simple(600, [[[100, 0, false], [200, 100, true], [0, 100, false]]]);
    $face = faceOf($part, $font, $this->directory);

    expect($face->outline($lastOn)->ops())->toEqual([
        ['M', 0.0, 100.0], ['Q', 100.0, 0.0, 200.0, 100.0], ['L', 0.0, 100.0], ['Z'],
    ])->and($face->outline($bothOff)->ops())->toEqual([
        ['M', 50.0, 50.0], ['Q', 100.0, 0.0, 200.0, 100.0], ['Q', 0.0, 100.0, 50.0, 50.0], ['Z'],
    ]);
})->with('lustrous faces');

it('places composite components by offset, by scale with one-byte offsets, and by matching points', function (string $part): void {
    $font = new TinyFont();
    $box = $font->simple(600, [[[0, 0, true], [100, 0, true], [100, 100, true]]]);
    $composite = $font->composite(600, [
        ['glyph' => $box, 'x' => 30, 'y' => -40],
        ['glyph' => $box, 'x' => -5, 'y' => 7, 'scale' => 0.5, 'bytes' => true],
        ['glyph' => $box, 'match' => [2, 0]],   // its point 0 onto the third point placed so far
    ], [0, -40, 130, 60]);

    expect(faceOf($part, $font, $this->directory)->outline($composite)->ops())->toEqual([
        ['M', 30.0, -40.0], ['L', 130.0, -40.0], ['L', 130.0, 60.0], ['L', 30.0, -40.0], ['Z'],
        ['M', -5.0, 7.0], ['L', 45.0, 7.0], ['L', 45.0, 57.0], ['L', -5.0, 7.0], ['Z'],
        ['M', 130.0, 60.0], ['L', 230.0, 60.0], ['L', 230.0, 160.0], ['L', 130.0, 60.0], ['Z'],
    ]);
})->with('lustrous faces');

it('moves an outline so its origin is where hmtx puts it, from the USE_MY_METRICS component in a composite', function (string $part): void {
    $font = new TinyFont();
    $shifted = $font->simple(600, [[[50, 0, true], [150, 0, true], [150, 100, true]]], bearing: 40);   // left edge 50, bearing 40
    $composite = $font->composite(600, [
        ['glyph' => $shifted, 'x' => 0, 'y' => 0, 'metrics' => true],
    ], [50, 0, 150, 100], bearing: 0);   // its own bearing would move it 50; its component's moves it -10
    $face = faceOf($part, $font, $this->directory);

    expect($face->outline($shifted)->ops()[0])->toEqual(['M', 40.0, 0.0])
        ->and($face->outline($composite)->ops()[0])->toEqual(['M', 40.0, 0.0]);
})->with('lustrous faces');

it('reads a face out of a collection by its index', function (string $part): void {
    $regular = new TinyFont();
    $regular->hhea = [800, -200, 0];
    $bold = new TinyFont();
    $bold->hhea = [900, -100, 0];
    $path = TinyFont::collection("{$this->directory}/pair.ttc", $regular, $bold);

    expect(new $part(new FontSource($path, 1))->metrics(1000)->ascent)->toBe(900.0)
        ->and(fn () => new $part(new FontSource($path, 2)))->toThrow(TypographyException::class, 'has 2 faces');

    $claimed = intdiv(filesize($path), 32) + 1;
    file_put_contents($path, substr_replace((string) file_get_contents($path), pack('N', $claimed), 8, 4));

    expect(fn () => new $part(new FontSource($path, 0)))->toThrow(TypographyException::class, "claims {$claimed} faces");
})->with('lustrous faces');

it('counts the faces of a collection only up to what its file has room for, so a scan never opens a claimed face past it', function (): void {
    $path = TinyFont::collection("{$this->directory}/pair.ttc", new TinyFont(), new TinyFont());
    $room = intdiv(filesize($path), 32);
    file_put_contents($path, substr_replace((string) file_get_contents($path), pack('N', $room + 1), 8, 4));

    expect(fn () => OpenTypeFile::faces($path))->toThrow(TypographyException::class, sprintf('claims %d faces', $room + 1));
});

// TinyFont's stand-in 'CFF ' table is four bytes: the PHP face reads real CFF (LustrousCffTest) and refuses this one as cut short
it('reads a CFF face\'s metrics, and refuses outlines from a CFF table it cannot read, by name', function (string $part): void {
    $font = new TinyFont();
    $font->cff = true;
    $face = faceOf($part, $font, $this->directory, 'cff.otf');

    expect($face->metrics(1000)->ascent)->toBe(800.0)
        ->and(fn () => $face->outline(0))->toThrow(TypographyException::class, 'CFF outlines');
})->with('lustrous faces');

it('refuses the outlines of a face with no glyf or CFF table by name, the same from both faces, and reads its metrics', function (string $part): void {
    $font = new TinyFont();
    $glyph = $font->simple(600, [[[0, 0, true], [600, 0, true], [600, 700, true]]]);
    $font->otherOutlines = 'hvgl';
    $path = $font->write("{$this->directory}/hvgl.ttf");
    $face = new $part(new FontSource($path));

    expect($face->advance($glyph))->toBe(600)
        ->and(fn () => $face->outline($glyph))->toThrow(TypographyException::class, "{$path}#0 has no outlines this reader draws: no 'glyf' or 'CFF ' table.");
})->with('lustrous faces');

it('gives an empty outline for a glyph that draws nothing in a face it can draw', function (string $part): void {
    $font = new TinyFont();
    $space = $font->simple(250, []);
    $face = faceOf($part, $font, $this->directory);

    expect($face->outline($space)->isEmpty())->toBeTrue();
})->with('lustrous faces');

it('refuses a font file cut short, as a TypographyException', function (string $part): void {
    $path = (new TinyFont())->write("{$this->directory}/whole.ttf");
    file_put_contents("{$this->directory}/cut.ttf", substr((string) file_get_contents($path), 0, 200));

    expect(fn () => new $part(new FontSource("{$this->directory}/cut.ttf")))->toThrow(TypographyException::class);
})->with('lustrous faces');
