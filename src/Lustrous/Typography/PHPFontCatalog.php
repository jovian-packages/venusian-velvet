<?php

namespace Jovian\Engines\Velvet\Lustrous\Typography;

use Surface\Contracts\Typography\FontSlant;
use Surface\Contracts\Typography\FontSource;
use Surface\Contracts\Typography\TypographyException;

/**
 * Lustrous's catalog in PHP: each font file described from its 'name', 'OS/2' and 'head' tables
 * with OpenTypeFile, and a character looked for in the face's own character map. What the two
 * Lustrous catalogs share (the scan, the cache, matching) is LustrousFontCatalog's.
 */
final class PHPFontCatalog extends LustrousFontCatalog
{
    /** @var array<string, CharacterMap|false> */
    private array $maps = [];

    protected function covers(FontSource $source, int $codepoint): bool
    {
        $map = $this->map($source);

        return $map !== false && $map->glyph($codepoint) !== 0;
    }

    /** The face's character map, read once; false when it has none or cannot be read. */
    private function map(FontSource $source): CharacterMap|false
    {
        if (! array_key_exists($source->key(), $this->maps)) {
            try {
                $file = new OpenTypeFile($source->path, $source->index);
                $cmap = $file->table('cmap');
                $file->close();
                $this->maps[$source->key()] = (is_null($cmap) ? null : CharacterMap::from($cmap)) ?? false;
            } catch (TypographyException) {
                $this->maps[$source->key()] = false;   // a broken file covers nothing
            }
        }

        return $this->maps[$source->key()];
    }

    /**
     * The faces of one file, as the cache keeps them. A file in a font directory that is not a
     * readable font has none; a broken face in it is passed over.
     *
     * @return list<array{int, string, int, string, int, list<array{string, float, float, float}>, string}> index, family, weight, slant, width class, axes, outline table
     *
     * @throws TypographyException When a file given by name is not a readable font.
     */
    protected function describe(string $path, bool $named): array
    {
        try {
            $count = OpenTypeFile::faces($path);
        } catch (TypographyException $e) {
            return $named ? throw $e : [];
        }
        $faces = [];
        for ($index = 0; $index < $count; $index++) {
            try {
                $faces[] = self::face(new FontSource($path, $index));
            } catch (TypographyException $e) {
                if ($named) {
                    throw $e;
                }
            }
        }

        return $faces;
    }

    /**
     * What the catalog matches a face by: its family ('name' 16, else 1), its weight and width class
     * (OS/2), its slant (OS/2, else 'head'), its 'fvar' axes, its outline table.
     *
     * @return array{int, string, int, string, int, list<array{string, float, float, float}>, string}
     *
     * @throws TypographyException When the face cannot be read or has no family name.
     */
    private static function face(FontSource $source): array
    {
        $file = new OpenTypeFile($source->path, $source->index);
        try {
            $names = self::names($file->table('name') ?? '');
            $family = $names[16] ?? $names[1] ?? null;
            if (is_null($family) || trim($family) === '') {
                throw new TypographyException("{$source->key()} has no family name.");
            }
            $os2 = $file->table('OS/2');
            $head = $file->table('head') ?? throw new TypographyException("{$source->key()} has no 'head' table.");
            $macStyle = OpenTypeFile::u16($head, 44);
            $weight = ($macStyle & 0x01) !== 0 ? 700 : 400;
            $slant = ($macStyle & 0x02) !== 0 ? FontSlant::ITALIC : FontSlant::UPRIGHT;
            $width = 5;
            if (! is_null($os2) && strlen($os2) >= 64) {
                $weight = OpenTypeFile::u16($os2, 4);
                $weight = match (true) {
                    $weight === 0 => 400,   // unset
                    $weight < 10 => $weight * 100,   // a few old faces count 1..9
                    default => min(1000, $weight),
                };
                $width = OpenTypeFile::u16($os2, 6) ?: 5;
                $selection = OpenTypeFile::u16($os2, 62);
                $slant = match (true) {
                    ($selection & 0x0001) !== 0 => FontSlant::ITALIC,
                    ($selection & 0x0200) !== 0 => FontSlant::OBLIQUE,
                    default => $slant,
                };
            }
            $axes = self::axesOf($file, $source->key());
            $outlines = match (true) {
                $file->has('glyf') => 'glyf',
                $file->has('CFF ') => 'CFF ',
                $file->has('CFF2') => 'CFF2',
                default => '',
            };
        } finally {
            $file->close();
        }

        return [$source->index, trim($family), $weight, $slant->value, $width, $axes, $outlines];
    }

    /**
     * The 'name' table's strings by name ID, for the IDs a catalog uses (1 family, 2 subfamily, 16
     * typographic family, 17 typographic subfamily): Windows English first, then any Windows
     * language, then Unicode, then Mac Roman English.
     *
     * @return array<int, string>
     */
    private static function names(string $name): array
    {
        if (strlen($name) < 6) {
            return [];
        }
        $count = OpenTypeFile::u16($name, 2);
        $strings = OpenTypeFile::u16($name, 4);
        if (6 + 12 * $count > strlen($name)) {
            return [];
        }
        $records = $count === 0 ? [] : unpack('n*', $name, 6);   // platform, encoding, language, id, length, offset; record after record
        $best = [];
        for ($i = 0, $field = 1; $i < $count; $i++, $field += 6) {
            $id = $records[$field + 3];
            if ($id !== 1 && $id !== 2 && $id !== 16 && $id !== 17) {
                continue;
            }
            [$platform, $encoding, $language] = [$records[$field], $records[$field + 1], $records[$field + 2]];
            $rank = match (true) {
                $platform === 3 && ($encoding === 1 || $encoding === 10) && $language === 0x409 => 0,
                $platform === 3 && ($encoding === 1 || $encoding === 10) => 1,
                $platform === 0 => 2,
                $platform === 1 && $encoding === 0 && $language === 0 => 3,
                default => null,
            };
            if (is_null($rank) || (isset($best[$id]) && $best[$id][0] <= $rank)) {
                continue;
            }
            $from = $strings + $records[$field + 5];
            $length = $records[$field + 4];
            if ($from + $length > strlen($name)) {
                continue;
            }
            $bytes = substr($name, $from, $length);
            $text = $platform === 1
                ? (preg_match('/[\x80-\xFF]/', $bytes) ? @iconv('MACINTOSH', 'UTF-8', $bytes) : $bytes)
                : @mb_convert_encoding($bytes, 'UTF-8', 'UTF-16BE');
            if (is_string($text) && $text !== '') {
                $best[$id] = [$rank, $text];
            }
        }

        return array_map(fn (array $entry) => $entry[1], $best);
    }
}
