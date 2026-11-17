<?php

namespace Jovian\Engines\Velvet\Lustrous\Glyphs;

use Surface\Contracts\Rendering\Glyphs\GlyphPainter;
use Surface\Contracts\Rendering\Rasterization\Edges;
use Surface\Rendering\Glyphs\GlyphDrawingDriver;
use Surface\Typography\Faces\FontFaceDriver;

/**
 * Lustrous's 'auto': its glyphs in C for a frame in C memory where ext-rasterize can keep them, in
 * PHP for every other frame. A frame's pixels decide, so neither store ever crosses into the other.
 */
class AutoGlyphDriver extends GlyphDrawingDriver
{
    public function __construct(
        private readonly ?FontFaceDriver $faces = null,
    ) {}

    public function driver(): string
    {
        return 'auto';
    }

    public function glyphs(Edges $edges): GlyphPainter
    {
        return new AutoGlyphs(
            CLandGlyphStore::available() ? (new CLandGlyphDriver($this->faces))->glyphs($edges) : null,
            (new PHPLandGlyphDriver($this->faces))->glyphs($edges),
        );
    }
}
