<?php

declare(strict_types=1);

use Jovian\Engines\Velvet\Tests\Fixtures\TinyFont;
use Surface\Contracts\Typography\FontRequest;
use Surface\Contracts\Typography\FontSlant;

/*
 * Lustrous's catalogs with variable faces beside static ones: a request lands on the face CSS Fonts
 * 4 matching gives, at the coordinates it gives.
 */

beforeEach(function (): void {
    $this->directory = sys_get_temp_dir().'/lustrous-variable-catalog-'.getmypid().'-'.bin2hex(random_bytes(4));
    mkdir($this->directory);
});

afterEach(function (): void {
    array_map('unlink', glob($this->directory.'/*'));
    rmdir($this->directory);
});

/**
 * A face of the family, written as the file named: its OS/2 weight and slant, its 'fvar' axes
 * (tag, minimum, default, maximum), the codepoints it maps.
 *
 * @param  list<array{string, float, float, float}>  $axes
 */
function flexFace(string $path, string $family, int $weight = 400, FontSlant $slant = FontSlant::UPRIGHT, array $axes = [], array $codepoints = [0x41]): string
{
    $font = new TinyFont();
    $font->family = $family;
    $font->weight = $weight;
    $font->italic = $slant === FontSlant::ITALIC;
    $font->oblique = $slant === FontSlant::OBLIQUE;
    $font->axes = $axes;
    $glyph = $font->simple(500, []);
    foreach ($codepoints as $codepoint) {
        $font->map($codepoint, $glyph);
    }

    return $font->write($path);
}

it('meets a weight inside a variable face\'s wght range as that wght, and clamps one outside it to compete by CSS\'s order', function (string $part, int $weight, string $file, string $at): void {
    flexFace("{$this->directory}/variable.ttf", 'Flex', axes: [['wght', 200, 400, 600]]);
    flexFace("{$this->directory}/black.ttf", 'Flex', 900);

    expect((new $part([$this->directory]))->match(new FontRequest(['Flex'], $weight))->key())->toBe("{$this->directory}/{$file}#0{$at}");
})->with('lustrous catalogs')->with([
    'inside the range' => [500, 'variable.ttf', '@wght=500'],
    'inside, below the default' => [300, 'variable.ttf', '@wght=300'],
    // over 500, heavier first: the range meets 550 exactly; its OS/2 400 would lose to 900
    'inside, over 500' => [550, 'variable.ttf', '@wght=550'],
    'the static face\'s own weight' => [900, 'black.ttf', ''],
    // over 500, heavier first: 900 before the range's 600
    'over 500, between them' => [650, 'black.ttf', ''],
    // under 400, lighter first, then heavier: 200 (the range's minimum) before 900
    'under the range' => [100, 'variable.ttf', '@wght=200'],
]);

it('names only the axes the request speaks to: weight and slant', function (string $part): void {
    $path = flexFace("{$this->directory}/wide.ttf", 'Wide', axes: [['wdth', 50, 100, 200], ['wght', 100, 400, 900], ['opsz', 8, 12, 72]]);

    expect((new $part([$this->directory]))->match(new FontRequest(['Wide'], 700))->key())->toBe("{$path}#0@wght=700");
})->with('lustrous catalogs');

it('offers italic and upright through ital, oblique through slnt at CSS\'s 14 degrees, clamped, then CSS\'s next slant', function (string $part, string $family, FontSlant $slant, string $at): void {
    flexFace("{$this->directory}/slanty.ttf", 'Slanty', axes: [['ital', 0, 0, 1], ['wght', 100, 400, 900]]);
    flexFace("{$this->directory}/leaning.ttf", 'Leaning', axes: [['slnt', -20, 0, 0]]);
    flexFace("{$this->directory}/shallow.ttf", 'Shallow', axes: [['slnt', -10, 0, 0]]);

    expect((new $part([$this->directory]))->match(new FontRequest([$family], 400, $slant))->key())->toBe("{$this->directory}/".strtolower($family).".ttf#0{$at}");
})->with('lustrous catalogs')->with([
    'italic through ital' => ['Slanty', FontSlant::ITALIC, '@ital=1,wght=400'],
    'upright through ital' => ['Slanty', FontSlant::UPRIGHT, '@ital=0,wght=400'],
    // no oblique: CSS's next is italic
    'oblique falls to italic' => ['Slanty', FontSlant::OBLIQUE, '@ital=1,wght=400'],
    'oblique through slnt' => ['Leaning', FontSlant::OBLIQUE, '@slnt=-14'],
    // no italic: CSS's next is oblique
    'italic falls to oblique' => ['Leaning', FontSlant::ITALIC, '@slnt=-14'],
    'upright through slnt' => ['Leaning', FontSlant::UPRIGHT, '@slnt=0'],
    'oblique clamped to slnt\'s range' => ['Shallow', FontSlant::OBLIQUE, '@slnt=-10'],
]);

it('prefers a face that offers the slant over one at a nearer weight that does not', function (string $part): void {
    flexFace("{$this->directory}/upright.ttf", 'Mixed', 700);
    $italic = flexFace("{$this->directory}/italic.ttf", 'Mixed', axes: [['ital', 0, 0, 1]]);

    expect((new $part([$this->directory]))->match(new FontRequest(['Mixed'], 700, FontSlant::ITALIC))->key())->toBe("{$italic}#0@ital=1");
})->with('lustrous catalogs');

it('covers a character with a variable face at the request\'s coordinates', function (string $part): void {
    flexFace("{$this->directory}/main.ttf", 'Main');
    $wide = flexFace("{$this->directory}/symbols.ttf", 'Symbols', axes: [['wght', 100, 400, 900]], codepoints: [0x41, 0x2603]);

    expect((new $part([$this->directory]))->covering(new FontRequest(['Main'], 650), 0x2603)?->key())->toBe("{$wide}#0@wght=650");
})->with('lustrous catalogs');

it('keeps the axes in its cache, and reads no cache of the format before them', function (string $part): void {
    $path = flexFace("{$this->directory}/variable.ttf", 'Flex', axes: [['wght', 200, 400, 600]]);
    $cache = "{$this->directory}/fonts.json";
    (new $part([$this->directory], cache: $cache))->match(new FontRequest(['Flex']));
    $kept = json_decode((string) file_get_contents($cache), true);
    $kept['files'][$path][2][0][5] = [['wght', 200, 400, 800]];   // a range only the cache holds
    file_put_contents($cache, json_encode($kept));

    expect((new $part([$this->directory], cache: $cache))->match(new FontRequest(['Flex'], 750))->key())->toBe("{$path}#0@wght=750");

    // version 1 kept five values a face and no axes: not read, the file is described again
    $stat = stat($path);
    file_put_contents($cache, json_encode(['version' => 1, 'files' => [$path => [$stat['mtime'], $stat['size'], [[0, 'Flex', 400, 'upright', 5]]]]]));

    expect((new $part([$this->directory], cache: $cache))->match(new FontRequest(['Flex'], 750))->key())->toBe("{$path}#0@wght=600");
})->with('lustrous catalogs');
