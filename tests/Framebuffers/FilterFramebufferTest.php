<?php

declare(strict_types=1);

use Surface\Contracts\Graphics\Effects\Blend;
use Surface\Contracts\Graphics\Effects\BlendMode;
use Surface\Contracts\Graphics\Effects\Blur;
use Surface\Contracts\Graphics\Effects\ColorMatrix;
use Surface\Contracts\Graphics\Effects\Composite;
use Surface\Contracts\Graphics\Effects\CompositeOperator;
use Surface\Contracts\Graphics\Effects\Displace;
use Surface\Contracts\Graphics\Effects\EdgeMode;
use Surface\Contracts\Graphics\Effects\Field;
use Surface\Contracts\Graphics\Effects\FilterChain;
use Surface\Contracts\Graphics\Effects\Flood;
use Surface\Contracts\Graphics\Effects\Noise;
use Surface\Contracts\Graphics\Effects\Table;
use Surface\Contracts\Rendering\Framebuffers\FormatSpec;
use Jovian\Engines\Velvet\Lustrous\Framebuffers\StoreFramebufferDriver;
use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\NutsAndBolts\Color;
use VelvetGE\Framebuffers\PHP\PHPLandFilters;
use Venusian\Topside\Tests\Support\Framebuffers\Rgba8Source;

function filteredOn(StoreFramebufferDriver $driver, FilterChain $chain, int $w, int $h, ?Region $region = null): string
{
    $buffer = $driver->full(FormatSpec::rgba8(), $w, $h);
    $buffer->writeRgba8(filterImage($w, $h), $w, $h);
    $buffer->filter($chain, $region);

    return $buffer->dump();
}


it('gives the bytes of the reference, whole surface and a region', function (StoreFramebufferDriver $driver, string $name): void {
    $chain = filterChains()[$name];
    $whole = filteredOn($driver, $chain, 24, 16);
    expect($whole)->toBe(PHPLandFilters::run($chain, filterImage(24, 16), 24, 16));

    // a region reads and writes only itself; noise is a function of the position on the surface
    $region = new Region(5, 3, 11, 9);
    $part = filteredOn($driver, $chain, 24, 16, $region);
    $image = filterImage(24, 16);
    $cut = '';
    for ($y = 3; $y < 12; $y++) {
        $cut .= substr($image, ($y * 24 + 5) * 4, 11 * 4);
    }
    $filtered = PHPLandFilters::run($chain, $cut, 11, 9, 5, 3);
    for ($y = 0; $y < 16; $y++) {
        for ($x = 0; $x < 24; $x++) {
            $inside = $region->x <= $x && $x < $region->right() && $region->y <= $y && $y < $region->bottom();
            $want = $inside ? substr($filtered, (($y - 3) * 11 + $x - 5) * 4, 4) : substr($image, ($y * 24 + $x) * 4, 4);
            expect(substr($part, ($y * 24 + $x) * 4, 4))->toBe($want);
        }
    }
})->with('framebuffer drivers')->with(array_keys(filterChains()));

it('takes premultiplied pixels as they are and writes them straight, with a chain of no steps too', function (StoreFramebufferDriver $driver): void {
    // premultiplied (64, 0, 0) at alpha 128 is straight (127.5, 0, 0) = (128, 0, 0): (64 × 255 + 64) / 128 = 128
    $buffer = $driver->full(FormatSpec::rgba8(), 2, 1);
    $buffer->writeRgba8("\x40\x00\x00\x80\x00\x00\x00\x00", 2, 1);
    $buffer->filter(FilterChain::of(), null, true);

    expect(bin2hex($buffer->dump()))->toBe('80000080'.'00000000');

    // with a step: the flood under the source ('over' with the source on top), premultiplied arithmetic throughout
    $buffer = $driver->full(FormatSpec::rgba8(), 1, 1);
    $buffer->writeRgba8("\x40\x00\x00\x80", 1, 1);
    $buffer->filter(FilterChain::of(new Flood(Color::rgb(0, 0, 255), 'f'), new Composite('f', CompositeOperator::OVER, in: 'source')), null, true);
    // r = 64 + 0 × (1 - 128/255) = 64; b = 0 + 255 × (127/255) = 127; alpha 255: straight the same
    expect(bin2hex($buffer->dump()))->toBe('4000'.'7f'.'ff');
})->with('framebuffer drivers');
