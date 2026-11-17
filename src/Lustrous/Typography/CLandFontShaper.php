<?php

namespace Jovian\Engines\Velvet\Lustrous\Typography;

use Surface\Contracts\Typography\FontShaper;
use Surface\Contracts\Typography\FontSource;
use Surface\Contracts\Typography\ShapedRun;
use TypogException;
use TypogFace;

/**
 * Lustrous's shaper in C: ext-typog shapes, by the same rules as the PHP shaper: one glyph a
 * character, 'kern' pairs on the first glyph, no GSUB, GPOS or AAT tables.
 */
final class CLandFontShaper implements FontShaper
{
    /** @var array<string, TypogFace> */
    private array $faces = [];

    public function shape(string $text, FontSource $source, float $size): ShapedRun
    {
        try {
            $face = $this->faces[$source->key()] ??= new TypogFace($source->path, $source->index, $source->variations);
            [$glyphs, $clusters, $advances] = $face->shape($text, $size);
        } catch (TypogException $e) {
            throw Typog::translated($e);
        }
        $zeros = array_fill(0, count($glyphs), 0.0);

        return new ShapedRun($source, $size, $glyphs, $clusters, $advances, $zeros, $zeros);
    }
}
