<?php

declare(strict_types=1);

use Jovian\Engines\Velvet\Tests\Fixtures\TinyFont;
use Surface\Contracts\Typography\FontSource;

/* Lustrous's shapers, in PHP and in C: one glyph a character, advances from 'hmtx', kerning from 'kern'. */

beforeEach(function (): void {
    $this->path = sys_get_temp_dir().'/lustrous-shaper-'.getmypid().'-'.bin2hex(random_bytes(4)).'.ttf';
});

afterEach(function (): void {
    @unlink($this->path);
});

it('gives one glyph a character, its advance scaled to the size, its cluster the byte it starts at', function (string $part): void {
    $font = new TinyFont();
    $font->unitsPerEm = 2000;
    [$a, $e] = [$font->simple(1200, []), $font->simple(900, [])];
    $font->map(0x41, $a)->map(0xE9, $e);
    $run = new $part()->shape('AéA', new FontSource($font->write($this->path)), 10.0);

    expect($run->glyphs)->toBe([$a, $e, $a])
        ->and($run->clusters)->toBe([0, 1, 3])
        ->and($run->advances)->toBe([6.0, 4.5, 6.0])
        ->and($run->width)->toBe(16.5);
})->with('lustrous shapers');

it('adds a kerning pair to the first glyph of the pair; values add across subtables unless one overrides', function (string $part): void {
    $font = new TinyFont();
    [$a, $v] = [$font->simple(600, []), $font->simple(600, [])];
    $font->map(0x41, $a)->map(0x56, $v);
    $font->kern([[$a, $v, -80], [$v, $a, -30]])->kern([[$a, $v, -20]])->kern([[$v, $a, -5]], override: true);
    $run = new $part()->shape('AVA', new FontSource($font->write($this->path)), 1000.0);

    expect($run->advances)->toBe([500.0, 595.0, 600.0]);
})->with('lustrous shapers');

it('gives a default ignorable character the face lacks the space glyph and no width', function (string $part): void {
    $font = new TinyFont();
    [$a, $space] = [$font->simple(600, []), $font->simple(250, [])];
    $font->map(0x41, $a)->map(0x20, $space);
    $run = new $part()->shape("A\u{200D}A", new FontSource($font->write($this->path)), 1000.0);

    expect($run->glyphs)->toBe([$a, $space, $a])
        ->and($run->advances)->toBe([600.0, 0.0, 600.0]);
})->with('lustrous shapers');
