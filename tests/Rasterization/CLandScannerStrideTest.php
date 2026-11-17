<?php

use Jovian\Engines\Velvet\Lustrous\Rasterization\CLandScanner;
use Surface\Contracts\Rendering\Framebuffers\BitDepth;
use Surface\Contracts\Rendering\Framebuffers\Endianness;
use Surface\Contracts\Rendering\Framebuffers\FormatSpec;
use Surface\Contracts\Rendering\Framebuffers\PixelFormat;
use Surface\Contracts\Rendering\Framebuffers\PixelMemory;
use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\Contracts\Rendering\Rasterization\Edges;
use Surface\Contracts\Rendering\Rasterization\Scanner;
use VelvetGE\Framebuffers\C\FbFormats;

/*
 * Lustrous-C draws in place into memory whose rows are padded (a library that aligns its rows, as
 * pixman does): ext-fb takes the stride. Held to the same shapes drawn into packed rows, and to
 * the padding never being written.
 */

dataset('padded formats', [
    'rgba8' => [FormatSpec::rgba8(), 4],
    'rgb888' => [new FormatSpec(PixelFormat::ROW_MAJOR, BitDepth::B24), 3],
    '565' => [new FormatSpec(PixelFormat::ROW_MAJOR, BitDepth::B16, endianness: Endianness::LSB), 2],
]);

it('draws in place into padded rows as into packed ones, and never into the padding', function (FormatSpec $format, int $bytes) {
    [$w, $h, $pad] = [33, 17, 7];
    $stride = $w * $bytes + $pad;
    $fb = FbFormats::from($format);
    $owner = new FbBuffer(new FbFormat(FB_LAYOUT_INDEX8), $stride, $h);
    $owner->rect(0, 0, $stride, $h, 0xA5);
    $packed = new FbBuffer($fb, $w, $h);
    $padded = FbBuffer::over($fb, $w, $h, $owner->pointer(), $owner, $stride);
    $background = random_bytes($w * $h * 4);
    $packed->blitRgba8($background, $w, $h, 0, 0);
    $padded->blitRgba8($background, $w, $h, 0, 0);

    $scanner = new CLandScanner(new Region(0, 0, $w, $h), Edges::ANTIALIASED);
    $ops = [
        [Scanner::PATH, [[2.5, 1.0, 30.2, 4.5, 18.0, 15.7]], 0],
        [Scanner::ELLIPSE, 16.0, 8.5, 11.3, 6.1],
        [Scanner::ROUND_RECT, 3.0, 2.0, 29.5, 14.0, [4.0, 4.0, 4.0, 4.0]],
    ];
    $colours = [0xC0FF2040, 0x8020A0FF, 0x60FFFFFF];
    // The ticket's owner is what keeps the memory alive, as a library's image is: not an FbBuffer
    // the scanner could take as the buffer itself.
    $into = fn (FbBuffer $b, int $s) => new PixelMemory($b->pointer(), $w, $h, $s, $format, new ArrayObject([$b]));

    expect($scanner->drawsInto($into($padded, $stride)))->toBeTrue();
    $scanner->fill($ops, $colours, $into($packed, $w * $bytes));
    $scanner->fill($ops, $colours, $into($padded, $stride));

    $padding = '';
    for ($y = 0; $y < $h; $y++) {
        $padding .= substr($owner->bytes(), $y * $stride + $w * $bytes, $pad);
    }
    expect($padded->rgba8())->toBe($packed->rgba8())
        ->and($padding)->toBe(str_repeat("\xA5", $pad * $h));
})->with('padded formats');
