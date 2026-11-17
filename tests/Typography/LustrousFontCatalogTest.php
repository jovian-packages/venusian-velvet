<?php

declare(strict_types=1);

use Jovian\Engines\Velvet\Tests\Fixtures\TinyFont;
use Surface\Contracts\Typography\FontRequest;
use Surface\Contracts\Typography\FontSlant;
use Surface\Contracts\Typography\TypographyException;

/* Lustrous's catalogs, in PHP and in C: faces found in a directory, matched as CSS matches a font. */

beforeEach(function (): void {
    $this->directory = sys_get_temp_dir().'/lustrous-catalog-'.getmypid().'-'.bin2hex(random_bytes(4));
    mkdir($this->directory.'/nested', 0777, true);
});

afterEach(function (): void {
    foreach ([...glob($this->directory.'/nested/*'), ...glob($this->directory.'/*.*')] as $file) {
        unlink($file);
    }
    rmdir($this->directory.'/nested');
    rmdir($this->directory);
});

/** A face of the family, weight and slant, mapping the codepoints given, written as the file named. */
function catalogFace(string $path, string $family, int $weight = 400, FontSlant $slant = FontSlant::UPRIGHT, array $codepoints = [0x41], int $width = 5, ?Closure $outlines = null): string
{
    $font = new TinyFont();
    if (! is_null($outlines)) {
        $outlines($font);
    }
    $font->family = $family;
    $font->weight = $weight;
    $font->width = $width;
    $font->italic = $slant === FontSlant::ITALIC;
    $font->oblique = $slant === FontSlant::OBLIQUE;
    $glyph = $font->simple(500, []);
    $format = max($codepoints) > 0xFFFF ? 12 : 4;   // as in real faces, a format 12 table holds every plane
    foreach ($codepoints as $codepoint) {
        $font->map($codepoint, $glyph, $format);
    }

    return $font->write($path);
}

it('matches the first of the request\'s families it has, a generic family by the families it stands for, then sans-serif', function (string $part): void {
    $serif = catalogFace("{$this->directory}/serif.ttf", 'Bookish');
    $sans = catalogFace("{$this->directory}/nested/sans.ttf", 'Plain');
    $catalog = new $part([$this->directory], generics: ['serif' => ['Missing', 'Bookish'], 'sans-serif' => ['Plain']]);

    expect($catalog->match(new FontRequest(['Nowhere', 'PLAIN', 'Bookish']))->path)->toBe($sans)
        ->and($catalog->match(new FontRequest(['serif']))->path)->toBe($serif)
        ->and($catalog->match(new FontRequest(['Nowhere']))->path)->toBe($sans);
})->with('lustrous catalogs');

it('chooses a weight in CSS\'s order: 400 to 500 heavier up to 500, then lighter; under 400 lighter first; over 500 heavier first', function (string $part): void {
    $faces = [];
    foreach ([300, 400, 480, 600, 700] as $weight) {
        $faces[$weight] = catalogFace("{$this->directory}/w{$weight}.ttf", 'Weights', $weight);
    }
    $catalog = new $part([$this->directory]);
    $match = fn (int $weight) => array_search($catalog->match(new FontRequest(['Weights'], $weight))->path, $faces, true);

    expect([$match(400), $match(450), $match(500), $match(350), $match(250), $match(650), $match(800)])
        ->toBe([400, 480, 480, 300, 300, 700, 700]);
})->with('lustrous catalogs');

it('chooses the slant asked for, then CSS\'s next one, and the width nearest normal', function (string $part): void {
    $upright = catalogFace("{$this->directory}/upright.ttf", 'Slants');
    $oblique = catalogFace("{$this->directory}/oblique.ttf", 'Slants', slant: FontSlant::OBLIQUE);
    $italic = catalogFace("{$this->directory}/italic.ttf", 'Slants', slant: FontSlant::ITALIC);
    catalogFace("{$this->directory}/condensed.ttf", 'Slants', width: 3);
    $catalog = new $part([$this->directory]);

    expect($catalog->match(new FontRequest(['Slants']))->path)->toBe($upright)
        ->and($catalog->match(new FontRequest(['Slants'], slant: FontSlant::ITALIC))->path)->toBe($italic);

    unlink($italic);

    expect((new $part([$this->directory]))->match(new FontRequest(['Slants'], slant: FontSlant::ITALIC))->path)->toBe($oblique);
})->with('lustrous catalogs');

it('finds a face for a character in the request\'s families, then the fallbacks, then every family by name', function (string $part): void {
    catalogFace("{$this->directory}/main.ttf", 'Main', codepoints: [0x41]);
    $hebrew = catalogFace("{$this->directory}/hebrew.ttf", 'Alpha Hebrew', codepoints: [0x41, 0x5D0]);
    $emoji = catalogFace("{$this->directory}/emoji.ttf", 'Zeta Emoji', codepoints: [0x1F600, 0x5D0]);
    $catalog = new $part([$this->directory], fallbacks: ['Zeta Emoji']);
    $request = new FontRequest(['Main']);

    expect($catalog->covering($request, 0x5D0)?->path)->toBe($emoji)   // a fallback before 'Alpha Hebrew' by name
        ->and($catalog->covering(new FontRequest(['Main']), 0x41)?->path)->toBe("{$this->directory}/main.ttf")
        ->and($catalog->covering(new FontRequest(['Main', 'Alpha Hebrew']), 0x5D0)?->path)->toBe($hebrew)
        ->and((new $part([$this->directory]))->covering($request, 0x5D0)?->path)->toBe($hebrew)   // no fallbacks: by name
        ->and($catalog->covering($request, 0x4E2D))->toBeNull();
})->with('lustrous catalogs');

it('keeps its scan in a cache and reads a file again only when its size or modified time changes', function (string $part): void {
    $path = catalogFace("{$this->directory}/face.ttf", 'Original');
    $other = catalogFace("{$this->directory}/other.ttf", 'Aardvark');   // what a request for no family it has gets
    $cache = "{$this->directory}/fonts.json";
    (new $part([$this->directory], cache: $cache))->match(new FontRequest());
    $kept = json_decode((string) file_get_contents($cache), true);
    $kept['files'][$path][2][0][1] = 'From The Cache';   // a name only the cache holds
    file_put_contents($cache, json_encode($kept));

    expect((new $part([$this->directory], cache: $cache))->match(new FontRequest(['From The Cache']))->path)->toBe($path);

    touch($path, filemtime($path) + 10);

    expect((new $part([$this->directory], cache: $cache))->match(new FontRequest(['From The Cache']))->path)->toBe($other)
        ->and(json_decode((string) file_get_contents($cache), true)['files'][$path][2][0][1])->toBe('Original');
})->with('lustrous catalogs');

it('refuses a collection that claims more faces than its file has room for, at 32 bytes a face', function (string $part): void {
    $good = new TinyFont();
    $good->family = 'Kept';
    $path = TinyFont::collection("{$this->directory}/claims.ttc", new TinyFont(), $good);
    $room = intdiv(filesize($path), 32);
    $claim = static function (int $faces) use ($path): void {
        file_put_contents($path, substr_replace((string) file_get_contents($path), pack('N', $faces), 8, 4));
    };

    $claim($room);
    $found = (new $part([$this->directory]))->match(new FontRequest(['Kept']))->key();
    $claim($room + 1);

    expect($found)->toBe("{$path}#1")
        ->and(fn () => (new $part([], [$path]))->match(new FontRequest()))
        ->toThrow(TypographyException::class, sprintf('claims %d faces', $room + 1));
})->with('lustrous catalogs');

it('passes over a file, or a face of a collection, in a directory that is not a font, and refuses a file it was given by name', function (string $part): void {
    $face = catalogFace("{$this->directory}/face.ttf", 'Real');
    file_put_contents("{$this->directory}/broken.ttf", 'not a font at all');
    $good = new TinyFont();
    $good->family = 'Kept';
    $nameless = new TinyFont();
    $nameless->family = ' ';
    $pair = TinyFont::collection("{$this->directory}/pair.ttc", $nameless, $good);   // only its face 0 is broken

    expect((new $part([$this->directory]))->match(new FontRequest(['Real']))->path)->toBe($face)
        ->and((new $part([$this->directory]))->match(new FontRequest(['Kept']))->key())->toBe("{$pair}#1")
        ->and(fn () => (new $part([], ["{$this->directory}/broken.ttf"]))->match(new FontRequest()))
        ->toThrow(TypographyException::class, 'not an OpenType or TrueType font');
})->with('lustrous catalogs');

it('never offers a face it cannot draw, CFF2 or outlines in a table it does not read, as a match or a fallback; CFF it offers', function (string $part): void {
    $drawn = catalogFace("{$this->directory}/drawn.ttf", 'Drawn', codepoints: [0x41, 0x5D0]);
    catalogFace("{$this->directory}/blank.ttf", 'Blank', codepoints: [0x41, 0x5D0], outlines: fn (TinyFont $f) => $f->otherOutlines = 'hvgl');
    catalogFace("{$this->directory}/flex.otf", 'Flex Blank', codepoints: [0x41, 0x5D0], outlines: fn (TinyFont $f) => $f->cff2 = true);
    $cubic = catalogFace("{$this->directory}/cubic.otf", 'Cubic', codepoints: [0x41], outlines: fn (TinyFont $f) => $f->cff = true);
    $catalog = new $part([$this->directory], fallbacks: ['Blank', 'Flex Blank']);

    // 'Blank' and 'Flex Blank' come first by name and as fallbacks; 'Drawn' is the only face that can draw U+05D0
    expect($catalog->match(new FontRequest(['Blank']))->path)->not->toBe("{$this->directory}/blank.ttf")
        ->and($catalog->match(new FontRequest(['Flex Blank']))->path)->not->toBe("{$this->directory}/flex.otf")
        ->and($catalog->covering(new FontRequest(['Blank']), 0x5D0)?->path)->toBe($drawn)
        ->and($catalog->match(new FontRequest(['Cubic']))->path)->toBe($cubic);
})->with('lustrous catalogs');

it('keeps each face\'s outline table in its cache, and reads no cache of the format before it', function (string $part): void {
    $blank = catalogFace("{$this->directory}/blank.ttf", 'Blank', outlines: fn (TinyFont $f) => $f->otherOutlines = 'hvgl');
    $drawn = catalogFace("{$this->directory}/drawn.ttf", 'Drawn');
    $cubic = catalogFace("{$this->directory}/cubic.otf", 'Cubic', outlines: fn (TinyFont $f) => $f->cff = true);
    $flex = catalogFace("{$this->directory}/flex.otf", 'Flex', outlines: fn (TinyFont $f) => $f->cff2 = true);
    $cache = "{$this->directory}/fonts.json";
    (new $part([$this->directory], cache: $cache))->match(new FontRequest());
    $kept = json_decode((string) file_get_contents($cache), true);

    // the PHP and C catalogs share the cache: each names the table as it is
    expect(array_map(fn (string $path) => $kept['files'][$path][2][0][6], [$blank, $drawn, $cubic, $flex]))->toBe(['', 'glyf', 'CFF ', 'CFF2']);

    $kept['files'][$blank][2][0][6] = 'glyf';   // what only the cache says
    file_put_contents($cache, json_encode($kept));

    expect((new $part([$this->directory], cache: $cache))->match(new FontRequest(['Blank']))->path)->toBe($blank);

    // version 2 kept no outline table: not read, each file described again
    $stat = stat($drawn);
    file_put_contents($cache, json_encode(['version' => 2, 'files' => [$drawn => [$stat['mtime'], $stat['size'], [[0, 'Drawn', 400, 'upright', 5, []]]]]]));

    expect((new $part([$this->directory], cache: $cache))->match(new FontRequest(['Drawn']))->path)->toBe($drawn);
})->with('lustrous catalogs');
