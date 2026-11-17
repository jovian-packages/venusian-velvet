<?php

namespace Jovian\Engines\Velvet\Lustrous\Typography;

use Surface\Contracts\Typography\FontSlant;
use Surface\Contracts\Typography\FontSource;
use TypogException;
use TypogFace;

/**
 * Lustrous's catalog in C: each font file described by ext-typog (TypogFace::describe), and a
 * character looked for in the face's character map through ext-typog. It describes a face as the
 * PHP catalog does, so the two share a cache file. What the two share (the scan, the cache,
 * matching) is LustrousFontCatalog's, 'fvar' axes included.
 */
final class CLandFontCatalog extends LustrousFontCatalog
{
    private const array SLANTS = [TYPOG_UPRIGHT => FontSlant::UPRIGHT, TYPOG_ITALIC => FontSlant::ITALIC, TYPOG_OBLIQUE => FontSlant::OBLIQUE];

    /** @var array<string, TypogFace|false> faces opened to read their character maps, then closed */
    private array $maps = [];

    protected function describe(string $path, bool $named): array
    {
        try {
            $faces = TypogFace::describe($path, ! $named);
        } catch (TypogException $e) {
            if ($named) {
                throw Typog::translated($e);
            }

            return [];
        }

        return array_map(fn (array $face) => [$face[0], $face[1], $face[2], self::SLANTS[$face[3]]->value, $face[4], $face[5], $face[6]], $faces);
    }

    protected function covers(FontSource $source, int $codepoint): bool
    {
        $key = $source->key();
        if (! array_key_exists($key, $this->maps)) {
            try {
                $face = new TypogFace($source->path, $source->index);
                $face->glyph(0);   // reads the character map, which stays readable once the file is closed
                $face->close();
                $this->maps[$key] = $face;
            } catch (TypogException) {
                $this->maps[$key] = false;   // a broken file covers nothing
            }
        }

        try {
            return $this->maps[$key] !== false && $this->maps[$key]->glyph($codepoint) !== 0;
        } catch (TypogException $e) {
            throw Typog::translated($e);
        }
    }
}
