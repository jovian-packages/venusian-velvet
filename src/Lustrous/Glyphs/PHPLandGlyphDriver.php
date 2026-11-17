<?php

namespace Jovian\Engines\Velvet\Lustrous\Glyphs;

use Surface\Contracts\Rendering\Rasterization\Edges;
use VelvetGE\Contracts\Glyphs\GlyphStore;
use VelvetGE\Glyphs\GlyphCacheDriver;

/** Lustrous's glyphs in PHP: always available. */
class PHPLandGlyphDriver extends GlyphCacheDriver
{
    public function driver(): string
    {
        return 'lustrous-php';
    }

    protected function store(Edges $edges): GlyphStore
    {
        return new PHPLandGlyphStore($edges);
    }
}
