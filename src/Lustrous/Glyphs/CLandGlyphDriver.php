<?php

namespace Jovian\Engines\Velvet\Lustrous\Glyphs;

use Surface\Contracts\Rendering\Rasterization\Edges;
use Surface\Contracts\Rendering\RenderingException;
use Surface\Typography\Faces\FontFaceDriver;
use VelvetGE\Contracts\Glyphs\GlyphStore;
use VelvetGE\Glyphs\GlyphCacheDriver;

/** Lustrous's glyphs in C, kept by ext-rasterize and painted through ext-fb: frames in C memory only. */
class CLandGlyphDriver extends GlyphCacheDriver
{
    /** @throws RenderingException When ext-rasterize has no RasterSpanStore, or ext-fb is not loaded. */
    public function __construct(?FontFaceDriver $faces = null)
    {
        CLandGlyphStore::available() || throw new RenderingException('Lustrous\'s C glyphs need ext-rasterize with RasterSpanStore, and ext-fb loaded for it to paint into.');
        parent::__construct($faces);
    }

    public function driver(): string
    {
        return 'lustrous-c';
    }

    protected function store(Edges $edges): GlyphStore
    {
        return new CLandGlyphStore($edges);
    }
}
