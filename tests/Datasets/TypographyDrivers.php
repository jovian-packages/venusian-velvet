<?php

declare(strict_types=1);

use Jovian\Engines\Velvet\Lustrous\Typography\CLandFontCatalog;
use Jovian\Engines\Velvet\Lustrous\Typography\CLandFontFace;
use Jovian\Engines\Velvet\Lustrous\Typography\CLandFontShaper;
use Jovian\Engines\Velvet\Lustrous\Typography\PHPFontCatalog;
use Jovian\Engines\Velvet\Lustrous\Typography\PHPFontFace;
use Jovian\Engines\Velvet\Lustrous\Typography\PHPFontShaper;
use Jovian\Engines\Velvet\Lustrous\Typography\Typog;

/*
 * Lustrous's typography in each form this PHP can run: lustrous-php always, lustrous-c with
 * ext-typog. Each yields the part's class, so a test makes its own.
 */

dataset('lustrous faces', function () {
    yield 'lustrous-php' => [PHPFontFace::class];
    if (Typog::available()) {
        yield 'lustrous-c' => [CLandFontFace::class];
    }
});

dataset('lustrous shapers', function () {
    yield 'lustrous-php' => [PHPFontShaper::class];
    if (Typog::available()) {
        yield 'lustrous-c' => [CLandFontShaper::class];
    }
});

// Variable fonts: lustrous-php always, lustrous-c with an ext-typog that draws them.
dataset('lustrous variable faces', function () {
    yield 'lustrous-php' => [PHPFontFace::class];
    if (Typog::available()) {
        yield 'lustrous-c' => [CLandFontFace::class];
    }
});

dataset('lustrous variable shapers', function () {
    yield 'lustrous-php' => [PHPFontShaper::class];
    if (Typog::available()) {
        yield 'lustrous-c' => [CLandFontShaper::class];
    }
});

dataset('lustrous catalogs', function () {
    yield 'lustrous-php' => [PHPFontCatalog::class];
    if (Typog::available()) {
        yield 'lustrous-c' => [CLandFontCatalog::class];
    }
});
