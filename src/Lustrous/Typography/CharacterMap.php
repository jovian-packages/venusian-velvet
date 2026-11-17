<?php

namespace Jovian\Engines\Velvet\Lustrous\Typography;

/**
 * A face's 'cmap': codepoint to glyph, looked up in the table's own bytes. It reads the Unicode
 * subtables in formats 12 (every plane) and 4 (the first plane), preferring 12, and a Windows symbol
 * subtable, where a codepoint up to U+00FF is also looked for at U+F000 up, as Windows and
 * HarfBuzz's own character map do (FreeType's lookup alone does not). A face with none of these maps
 * nothing.
 */
final class CharacterMap
{
    /**
     * [platform, encoding, format], best first: a subtable for every plane before one for the first,
     * Windows before Unicode, a symbol subtable last.
     *
     * @var list<array{int, int, int}>
     */
    private const array PREFERRED = [
        [3, 10, 12], [0, 6, 12], [0, 4, 12], [0, 3, 12], [0, 2, 12], [0, 1, 12], [0, 0, 12], [3, 1, 12],
        [3, 1, 4], [0, 3, 4], [0, 2, 4], [0, 1, 4], [0, 0, 4],
        [3, 0, 4],
    ];

    /** Lookups kept: text repeats its characters. */
    private const int KEPT = 4096;

    /** @var array<int, int> */
    private array $glyphs = [];

    private function __construct(
        private readonly string $cmap,
        private readonly int $at,
        private readonly int $format,
        private readonly bool $symbol,
    ) {}

    /**
     * The best subtable of a 'cmap' table; null when it has none this reads.
     *
     * @throws \Surface\Contracts\Typography\TypographyException When the table is cut short.
     */
    public static function from(string $cmap): ?self
    {
        $records = OpenTypeFile::u16($cmap, 2);
        $best = null;
        for ($i = 0; $i < $records; $i++) {
            $platform = OpenTypeFile::u16($cmap, 4 + 8 * $i);
            $encoding = OpenTypeFile::u16($cmap, 6 + 8 * $i);
            $offset = OpenTypeFile::u32($cmap, 8 + 8 * $i);
            $format = OpenTypeFile::u16($cmap, $offset);
            $rank = array_search([$platform, $encoding, $format], self::PREFERRED, true);
            if ($rank !== false && (is_null($best) || $rank < $best[0])) {
                $best = [$rank, $offset, $format, $platform === 3 && $encoding === 0];
            }
        }

        return is_null($best) ? null : new self($cmap, $best[1], $best[2], $best[3]);
    }

    /** The glyph for the codepoint; 0 when there is none. */
    public function glyph(int $codepoint): int
    {
        if (isset($this->glyphs[$codepoint])) {
            return $this->glyphs[$codepoint];
        }
        if (count($this->glyphs) >= self::KEPT) {
            $this->glyphs = [];
        }
        $glyph = $this->look($codepoint);
        if ($glyph === 0 && $this->symbol && $codepoint <= 0xFF) {
            $glyph = $this->look(0xF000 + $codepoint);
        }

        return $this->glyphs[$codepoint] = $glyph;
    }

    private function look(int $codepoint): int
    {
        return $this->format === 12 ? $this->look12($codepoint) : $this->look4($codepoint);
    }

    /** Format 12: groups of consecutive codepoints mapped to consecutive glyphs, sorted. */
    private function look12(int $codepoint): int
    {
        $groups = OpenTypeFile::u32($this->cmap, $this->at + 12);
        [$low, $high] = [0, $groups - 1];
        while ($low <= $high) {
            $middle = ($low + $high) >> 1;
            $group = $this->at + 16 + 12 * $middle;
            if ($codepoint < OpenTypeFile::u32($this->cmap, $group)) {
                $high = $middle - 1;
            } elseif ($codepoint > OpenTypeFile::u32($this->cmap, $group + 4)) {
                $low = $middle + 1;
            } else {
                return OpenTypeFile::u32($this->cmap, $group + 8) + $codepoint - OpenTypeFile::u32($this->cmap, $group);
            }
        }

        return 0;
    }

    /** Format 4: segments of the first plane, each mapped by a delta or through the glyph array. */
    private function look4(int $codepoint): int
    {
        if ($codepoint > 0xFFFF) {
            return 0;
        }
        $segments = OpenTypeFile::u16($this->cmap, $this->at + 6) >> 1;
        $ends = $this->at + 14;
        [$low, $high] = [0, $segments - 1];
        while ($low < $high) {   // the first segment whose end is at or past the codepoint
            $middle = ($low + $high) >> 1;
            if (OpenTypeFile::u16($this->cmap, $ends + 2 * $middle) < $codepoint) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }
        if ($segments === 0 || OpenTypeFile::u16($this->cmap, $ends + 2 * $low) < $codepoint) {
            return 0;
        }
        $starts = $ends + 2 * $segments + 2;
        if (OpenTypeFile::u16($this->cmap, $starts + 2 * $low) > $codepoint) {
            return 0;
        }
        $delta = OpenTypeFile::u16($this->cmap, $starts + 2 * $segments + 2 * $low);
        $rangeAt = $starts + 4 * $segments + 2 * $low;
        $range = OpenTypeFile::u16($this->cmap, $rangeAt);
        if ($range === 0) {
            return ($codepoint + $delta) & 0xFFFF;
        }
        $glyph = OpenTypeFile::u16($this->cmap, $rangeAt + $range + 2 * ($codepoint - OpenTypeFile::u16($this->cmap, $starts + 2 * $low)));

        return $glyph === 0 ? 0 : ($glyph + $delta) & 0xFFFF;
    }
}
