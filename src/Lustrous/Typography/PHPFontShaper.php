<?php

namespace Jovian\Engines\Velvet\Lustrous\Typography;

use Surface\Contracts\Typography\FontShaper;
use Surface\Contracts\Typography\FontSource;
use Surface\Contracts\Typography\ShapedRun;
use Surface\Contracts\Typography\TypographyException;
use Surface\Typography\Support\DefaultIgnorable;

/**
 * Lustrous's shaper in PHP: one glyph a character, from the face's character map, moved by its
 * advance and by the face's 'kern' table. It does not read GSUB or GPOS, so it makes no ligatures,
 * no contextual forms and no GPOS kerning: what it shapes well is text that needs none, such as
 * Latin, Greek and Cyrillic in a face whose kerning is in 'kern'.
 *
 * A default ignorable character the face lacks takes the space glyph and no width, as HarfBuzz
 * does. A kerning value is added to the advance of the first glyph of the pair, as HarfBuzz does.
 *
 * Glyphs and advances come from Lustrous's PHP face for the source, so a variable font is shaped at
 * the instance the source names, with the advances the face gives there ('HVAR', or 'gvar''s
 * phantom points). 'kern' has no variations.
 */
final class PHPFontShaper implements FontShaper
{
    /** @var array<string, array{PHPFontFace, array<int, int>, int}> source key => face, kerning pairs, space glyph */
    private array $faces = [];

    public function shape(string $text, FontSource $source, float $size): ShapedRun
    {
        [$face, $kerning, $space] = $this->faces[$source->key()] ??= self::read($source);
        $scale = $size / $face->unitsPerEm();
        [$glyphs, $clusters, $advances] = [[], [], []];
        preg_match_all('/./su', $text, $characters, PREG_OFFSET_CAPTURE);
        foreach ($characters[0] as [$character, $offset]) {
            $codepoint = mb_ord($character);
            $glyph = $face->glyph($codepoint);
            $ignored = $glyph === 0 && DefaultIgnorable::contains($codepoint);
            if ($ignored) {
                $glyph = $space;
            }
            $previous = array_key_last($glyphs);
            if (! is_null($previous) && $kerning !== [] && isset($kerning[($glyphs[$previous] << 16) | $glyph])) {
                $advances[$previous] += $kerning[($glyphs[$previous] << 16) | $glyph] * $scale;
            }
            $glyphs[] = $glyph;
            $clusters[] = $offset;
            $advances[] = $ignored ? 0.0 : $face->advance($glyph) * $scale;
        }
        $zeros = array_fill(0, count($glyphs), 0.0);

        return new ShapedRun($source, $size, $glyphs, $clusters, $advances, $zeros, $zeros);
    }

    /**
     * What shaping needs from a face, read once: the face, its kerning pairs and its space glyph.
     *
     * @return array{PHPFontFace, array<int, int>, int}
     */
    private static function read(FontSource $source): array
    {
        $face = new PHPFontFace($source);
        $file = new OpenTypeFile($source->path, $source->index);
        try {
            $kern = $file->table('kern');
            $kerning = is_null($kern) ? [] : self::kerning($kern);
        } finally {
            $file->close();
        }

        return [$face, $kerning, $face->glyph(0x20)];
    }

    /**
     * The 'kern' table's horizontal pairs, from its format 0 subtables, Microsoft's layout or Apple's.
     * Cross-stream subtables are skipped. Values for the same pair add up, unless a subtable has the override
     * bit, whose value for a pair replaces the sum so far.
     *
     * @return array<int, int> (left << 16 | right) => value in font units
     */
    private static function kerning(string $kern): array
    {
        $apple = OpenTypeFile::u16($kern, 0) === 1;
        $tables = $apple ? OpenTypeFile::u32($kern, 4) : OpenTypeFile::u16($kern, 2);
        $at = $apple ? 8 : 4;
        $pairs = [];
        for ($t = 0; $t < $tables; $t++) {
            if ($apple) {
                $length = OpenTypeFile::u32($kern, $at);
                $coverage = OpenTypeFile::u16($kern, $at + 4);
                $usable = ($coverage & 0xE000) === 0 && ($coverage & 0xFF) === 0;   // horizontal, not cross-stream, no variation; format 0
                $override = false;
                $body = $at + 8;
            } else {
                $length = OpenTypeFile::u16($kern, $at + 2);
                $coverage = OpenTypeFile::u16($kern, $at + 4);
                $usable = ($coverage & 0x0001) !== 0 && ($coverage & 0x0004) === 0 && ($coverage >> 8) === 0;   // horizontal, not cross-stream; format 0
                $override = ($coverage & 0x0008) !== 0;
                $body = $at + 6;
            }
            if ($length < 6) {
                throw new TypographyException('A \'kern\' subtable is shorter than its own header.');
            }
            if ($usable) {
                $count = OpenTypeFile::u16($kern, $body);
                if ($body + 8 + 6 * $count > strlen($kern)) {
                    throw new TypographyException('A \'kern\' subtable holds fewer pairs than it says.');
                }
                $flat = $count === 0 ? [] : unpack('n*', substr($kern, $body + 8, 6 * $count));
                for ($i = 1; $i <= 3 * $count; $i += 3) {
                    $key = ($flat[$i] << 16) | $flat[$i + 1];
                    $value = $flat[$i + 2];
                    $value = $value >= 0x8000 ? $value - 0x10000 : $value;
                    $pairs[$key] = $override ? $value : ($pairs[$key] ?? 0) + $value;
                }
            }
            $at += $length;
        }

        return $pairs;
    }
}
