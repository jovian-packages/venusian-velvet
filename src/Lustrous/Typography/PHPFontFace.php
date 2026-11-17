<?php

namespace Jovian\Engines\Velvet\Lustrous\Typography;

use Surface\Contracts\Typography\FontFace;
use Surface\Contracts\Typography\FontMetrics;
use Surface\Contracts\Typography\FontSource;
use Surface\Contracts\Typography\TypographyException;
use Surface\NutsAndBolts\Path;

/**
 * Lustrous's face in PHP: an OpenType file read table by table. It reads metrics, the character map
 * and advances from every OpenType face, and outlines from TrueType ('glyf') faces, simple and
 * composite, and from CFF ('CFF ') faces, name-keyed and CID-keyed (CompactFontFormat), unhinted.
 * A face with CFF2 outlines is read for everything but its outlines, which it refuses by name.
 *
 * Vertical metrics are those OpenType recommends: the OS/2 typographic ones when the face sets
 * USE_TYPO_METRICS; else 'hhea''s; else, when 'hhea''s are zero, OS/2's typographic ones, then its
 * Windows ones.
 *
 * A variable font is drawn at the instance its source's variations name (FontVariations): 'gvar'
 * moves each glyph's points and its phantom points (inferring deltas for the points a tuple does
 * not name, in simple glyphs) and component offsets; 'HVAR' varies advances, or without it the
 * phantom points do; 'MVAR' varies the OS/2 typographic and Windows metrics it has tags for
 * ('hhea''s have none). Points stay at full precision; an advance is rounded to a whole unit, as
 * the contract asks for one. Coordinates on a face without 'fvar' have nothing to apply to: the
 * face is drawn as it is.
 */
final class PHPFontFace implements FontFace
{
    /** Outlines kept, least recently used first out. */
    private const int KEPT = 1024;

    /** Composite glyphs nested deeper than this are taken as broken. */
    private const int DEPTH = 8;

    private readonly OpenTypeFile $file;

    private readonly int $unitsPerEm;

    private readonly int $glyphCount;

    private readonly int $longMetrics;

    private readonly bool $longOffsets;

    private readonly string $hmtx;

    private readonly ?CharacterMap $cmap;

    /** @var array{float, float, float} ascender (up), descender (down, negative), line gap; font units */
    private readonly array $vertical;

    /** The instance the source names; null for a face drawn as it is. */
    private readonly ?FontVariations $variations;

    /** @var array<int, array{Path, float, float}> glyph => outline and the x of its first two phantom points */
    private array $outlines = [];

    /** The 'CFF ' table read, at the first outline of a CFF face. */
    private ?CompactFontFormat $cff = null;

    /** @var array<int, Path> glyph => CFF outline, least recently used first out */
    private array $cffOutlines = [];

    /** @var array<int, int> glyph => advance at the instance */
    private array $advances = [];

    /** @throws TypographyException When the file is not a font, or lacks a table every face has. */
    public function __construct(private readonly FontSource $source)
    {
        $this->file = new OpenTypeFile($source->path, $source->index);
        $head = $this->required('head');
        $hhea = $this->required('hhea');
        $this->unitsPerEm = OpenTypeFile::u16($head, 18);
        if ($this->unitsPerEm < 16 || $this->unitsPerEm > 16384) {
            throw new TypographyException("{$source->key()} has {$this->unitsPerEm} units an em; a font has 16 to 16384.");
        }
        $this->longOffsets = OpenTypeFile::i16($head, 50) === 1;
        $this->glyphCount = OpenTypeFile::u16($this->required('maxp'), 4);
        $this->longMetrics = OpenTypeFile::u16($hhea, 34);
        $this->hmtx = $this->required('hmtx');
        if ($this->longMetrics === 0 || strlen($this->hmtx) < 4 * $this->longMetrics) {
            throw new TypographyException("{$source->key()}'s 'hmtx' table is shorter than its 'hhea' says.");
        }
        $cmap = $this->file->table('cmap');
        $this->cmap = is_null($cmap) ? null : CharacterMap::from($cmap);
        $this->variations = FontVariations::of($this->file, $source);
        $this->vertical = self::vertical($hhea, $this->file->table('OS/2'), $this->variations);
    }

    public function source(): FontSource
    {
        return $this->source;
    }

    public function unitsPerEm(): int
    {
        return $this->unitsPerEm;
    }

    public function glyphCount(): int
    {
        return $this->glyphCount;
    }

    public function metrics(float $size): FontMetrics
    {
        $scale = $size / $this->unitsPerEm;
        [$ascender, $descender, $gap] = $this->vertical;

        return new FontMetrics($ascender * $scale, -$descender * $scale, $gap * $scale);
    }

    public function glyph(int $codepoint): int
    {
        $glyph = $this->cmap?->glyph($codepoint) ?? 0;

        return $glyph < $this->glyphCount ? $glyph : 0;
    }

    public function advance(int $glyph): int
    {
        if ($glyph < 0 || $glyph >= $this->glyphCount) {
            return 0;
        }
        $advance = $this->hmtxAdvance($glyph);
        if (is_null($this->variations)) {
            return $advance;
        }

        return $this->advances[$glyph] ??= match (true) {
            $this->variations->hasAdvances() => (int) round($advance + $this->variations->advance($glyph)),
            $this->file->has('glyf') => (int) round($this->loaded($glyph)[2] - $this->loaded($glyph)[1]),
            default => $advance,
        };
    }

    public function outline(int $glyph): Path
    {
        if (! $this->file->has('glyf')) {
            if ($this->file->has('CFF2')) {
                throw new TypographyException("{$this->source->key()} has CFF2 outlines, which this reader does not draw.");
            }
            if ($this->file->has('CFF ')) {
                return $this->cffOutline($glyph);
            }

            // bitmaps only, or outlines in a table this does not read ('hvgl', ...): nothing it can draw
            throw new TypographyException("{$this->source->key()} has no outlines this reader draws: no 'glyf' or 'CFF ' table.");
        }

        return $this->loaded($glyph)[0];
    }

    /** Closes the file. */
    public function close(): void
    {
        $this->file->close();
        $this->outlines = [];
        $this->cffOutlines = [];
    }

    /** A CFF glyph's outline, kept least recently used first out. */
    private function cffOutline(int $glyph): Path
    {
        if (isset($this->cffOutlines[$glyph])) {
            $path = $this->cffOutlines[$glyph];
            unset($this->cffOutlines[$glyph]);

            return $this->cffOutlines[$glyph] = $path;
        }
        if ($glyph < 0 || $glyph >= $this->glyphCount) {
            throw new TypographyException("{$this->source->key()} has {$this->glyphCount} glyphs; there is no glyph {$glyph}.");
        }
        $this->cff ??= new CompactFontFormat($this->file->table('CFF '), $this->source->key());
        $path = $this->cff->outline($glyph);
        if (count($this->cffOutlines) >= self::KEPT) {
            unset($this->cffOutlines[array_key_first($this->cffOutlines)]);
        }

        return $this->cffOutlines[$glyph] = $path;
    }

    /**
     * The glyph loaded, kept least recently used first out: its outline, moved across so its origin
     * is its first phantom point, and the x of its first two phantom points.
     *
     * @return array{Path, float, float}
     */
    private function loaded(int $glyph): array
    {
        if (isset($this->outlines[$glyph])) {
            $loaded = $this->outlines[$glyph];
            unset($this->outlines[$glyph]);

            return $this->outlines[$glyph] = $loaded;
        }
        [$contours, $first, $second] = $this->load($glyph, 0);
        if (count($this->outlines) >= self::KEPT) {
            unset($this->outlines[array_key_first($this->outlines)]);
        }

        return $this->outlines[$glyph] = [self::path($contours, -$first), $first, $second];
    }

    /**
     * The glyph's contours, each a list of points [x, y, on curve] in font units, and the x of its
     * first two phantom points: OpenType's left one (its left edge less its left side bearing) and
     * right one (that plus its advance), at the instance. A composite glyph with a component that
     * says USE_MY_METRICS takes that component's phantom points, as they stand in the component
     * (the last such component's, when there are several).
     *
     * @return array{list<list<array{float, float, bool}>>, float, float}
     */
    private function load(int $glyph, int $depth): array
    {
        if ($glyph >= $this->glyphCount) {
            throw new TypographyException("{$this->source->key()} has {$this->glyphCount} glyphs; there is no glyph {$glyph}.");
        }
        $data = $this->glyphData($glyph);
        $first = (float) (($data === '' ? 0 : OpenTypeFile::i16($data, 2)) - $this->bearing($glyph));
        $second = $first + $this->hmtxAdvance($glyph);
        if ($data === '') {   // draws nothing; its phantom points may still vary
            $deltas = $this->variations?->glyph($glyph, self::withPhantoms([], $first, $second), []);
            if (! is_null($deltas)) {
                [$first, $second] = [$first + $deltas[0][0], $second + $deltas[1][0]];
            }

            return [[], $first, $second];
        }
        $count = OpenTypeFile::i16($data, 0);

        return $count >= 0
            ? $this->loadSimple($glyph, $this->simple($data, $count), $first, $second)
            : $this->loadComposite($glyph, $data, $depth, $first, $second);
    }

    /**
     * @param  list<list<array{float, float, bool}>>  $contours
     * @return array{list<list<array{float, float, bool}>>, float, float}
     */
    private function loadSimple(int $glyph, array $contours, float $first, float $second): array
    {
        if (is_null($this->variations)) {
            return [$contours, $first, $second];
        }
        [$points, $ends] = [[], []];
        foreach ($contours as $contour) {
            foreach ($contour as [$x, $y]) {
                $points[] = [$x, $y];
            }
            $ends[] = count($points) - 1;
        }
        $count = count($points);
        $deltas = $this->variations->glyph($glyph, self::withPhantoms($points, $first, $second), $ends);
        if (is_null($deltas)) {
            return [$contours, $first, $second];
        }
        $i = 0;
        foreach ($contours as $c => $contour) {
            foreach ($contour as $p => [$x, $y, $on]) {
                $contours[$c][$p] = [$x + $deltas[$i][0], $y + $deltas[$i][1], $on];
                $i++;
            }
        }

        return [$contours, $first + $deltas[$count][0], $second + $deltas[$count + 1][0]];
    }

    /**
     * A glyph made of other glyphs, each moved and transformed: by an offset (which 'gvar' varies,
     * one point a component), or by matching one of its points to a point placed before it.
     *
     * @return array{list<list<array{float, float, bool}>>, float, float}
     */
    private function loadComposite(int $glyph, string $data, int $depth, float $first, float $second): array
    {
        if ($depth >= self::DEPTH) {
            throw new TypographyException("{$this->source->key()}: composite glyphs nested more than ".self::DEPTH.' deep.');
        }
        $components = self::components($data);
        $deltas = null;
        if (! is_null($this->variations)) {
            $offsets = array_map(fn (array $component) => [(float) $component[2], (float) $component[3]], $components);
            $deltas = $this->variations->glyph($glyph, self::withPhantoms($offsets, $first, $second), null);
        }
        if (! is_null($deltas)) {
            $at = count($components);
            [$first, $second] = [$first + $deltas[$at][0], $second + $deltas[$at + 1][0]];
        }
        $metrics = null;
        $contours = [];
        $placed = [];   // every point so far, in order: what point matching counts
        foreach ($components as $k => [$flags, $component, $one, $two, $a, $b, $c, $d]) {
            [$own, $componentFirst, $componentSecond] = $this->load($component, $depth + 1);
            if (($flags & 0x0200) !== 0) {   // USE_MY_METRICS: the last one says
                $metrics = [$componentFirst, $componentSecond];
            }
            $parts = [];
            $points = [];
            foreach ($own as $contour) {
                $part = [];
                foreach ($contour as [$x, $y, $on]) {
                    $part[] = $point = [$a * $x + $c * $y, $b * $x + $d * $y, $on];
                    $points[] = $point;
                }
                $parts[] = $part;
            }
            if (($flags & 0x0002) !== 0) {   // ARGS_ARE_XY_VALUES
                [$dx, $dy] = [$one + ($deltas[$k][0] ?? 0.0), $two + ($deltas[$k][1] ?? 0.0)];
                if (($flags & 0x0800) !== 0 && ($flags & 0x1000) === 0) {   // SCALED_COMPONENT_OFFSET
                    [$dx, $dy] = [$a * $dx + $c * $dy, $b * $dx + $d * $dy];
                }
            } else {
                if (! isset($placed[$one], $points[$two])) {
                    throw new TypographyException("{$this->source->key()}: a composite glyph matches a point it does not have.");
                }
                [$dx, $dy] = [$placed[$one][0] - $points[$two][0], $placed[$one][1] - $points[$two][1]];
            }
            foreach ($parts as $part) {
                foreach ($part as $at => [$x, $y, $on]) {
                    $part[$at] = $placed[] = [$x + $dx, $y + $dy, $on];
                }
                $contours[] = $part;
            }
        }
        if (! is_null($metrics)) {
            [$first, $second] = $metrics;
        }

        return [$contours, $first, $second];
    }

    /**
     * Points with the four phantom points after them: left and right (x from the metrics, y 0), then
     * top and bottom, which Lustrous does not use and keeps at 0.
     *
     * @param  list<array{float, float}>  $points
     * @return list<array{float, float}>
     */
    private static function withPhantoms(array $points, float $first, float $second): array
    {
        array_push($points, [$first, 0.0], [$second, 0.0], [0.0, 0.0], [0.0, 0.0]);

        return $points;
    }

    /** The glyph's advance in 'hmtx': past the long metrics, the last long one's. */
    private function hmtxAdvance(int $glyph): int
    {
        return OpenTypeFile::u16($this->hmtx, 4 * min($glyph, $this->longMetrics - 1));
    }

    /** The glyph's left side bearing in 'hmtx'. */
    private function bearing(int $glyph): int
    {
        return $glyph < $this->longMetrics
            ? OpenTypeFile::i16($this->hmtx, 4 * $glyph + 2)
            : OpenTypeFile::i16($this->hmtx, 4 * $this->longMetrics + 2 * ($glyph - $this->longMetrics));
    }

    private function required(string $tag): string
    {
        return $this->file->table($tag) ?? throw new TypographyException("{$this->source->key()} has no '{$tag}' table.");
    }

    /**
     * The face's vertical metrics, chosen from the face's own values, then varied by the MVAR tags
     * for the values chosen: 'hasc', 'hdsc', 'hlgp' for OS/2's typographic ones, 'hcla', 'hcld' for
     * its Windows ones. 'hhea''s have no MVAR tag.
     *
     * @return array{float, float, float}
     */
    private static function vertical(string $hhea, ?string $os2, ?FontVariations $variations): array
    {
        $metric = fn (string $tag): float => $variations?->metric($tag) ?? 0.0;
        $typo = null;
        $windows = null;
        if (! is_null($os2) && strlen($os2) >= 78) {
            $typo = [OpenTypeFile::i16($os2, 68), OpenTypeFile::i16($os2, 70), OpenTypeFile::i16($os2, 72)];
            $windows = [OpenTypeFile::u16($os2, 74), OpenTypeFile::u16($os2, 76)];
            $variedTypo = [$typo[0] + $metric('hasc'), $typo[1] + $metric('hdsc'), $typo[2] + $metric('hlgp')];
            if ((OpenTypeFile::u16($os2, 62) & 0x80) !== 0) {   // USE_TYPO_METRICS
                return $variedTypo;
            }
        }
        $own = [OpenTypeFile::i16($hhea, 4), OpenTypeFile::i16($hhea, 6), OpenTypeFile::i16($hhea, 8)];
        if ($own[0] !== 0 || $own[1] !== 0 || is_null($typo)) {
            return array_map('floatval', $own);
        }
        if ($typo[0] !== 0 || $typo[1] !== 0) {
            return $variedTypo;
        }

        return [$windows[0] + $metric('hcla'), -($windows[1] + $metric('hcld')), 0.0];
    }

    /** The glyph's bytes in 'glyf'; empty for a glyph that draws nothing. */
    private function glyphData(int $glyph): string
    {
        $loca = $this->required('loca');
        [$start, $end] = $this->longOffsets
            ? [OpenTypeFile::u32($loca, 4 * $glyph), OpenTypeFile::u32($loca, 4 * $glyph + 4)]
            : [2 * OpenTypeFile::u16($loca, 2 * $glyph), 2 * OpenTypeFile::u16($loca, 2 * $glyph + 2)];
        if ($end < $start) {
            throw new TypographyException("{$this->source->key()}'s glyph {$glyph} ends before it starts.");
        }

        return $end === $start ? '' : $this->file->part('glyf', $start, $end - $start);
    }

    /** @return list<list<array{float, float, bool}>> */
    private function simple(string $data, int $count): array
    {
        if ($count === 0) {
            return [];
        }
        $ends = [];
        for ($i = 0; $i < $count; $i++) {
            $ends[] = OpenTypeFile::u16($data, 10 + 2 * $i);
        }
        $points = $ends[$count - 1] + 1;
        $at = 12 + 2 * $count + OpenTypeFile::u16($data, 10 + 2 * $count);
        $length = strlen($data);

        $flags = [];
        while (count($flags) < $points) {
            if ($at >= $length) {
                throw new TypographyException("{$this->source->key()}: a glyph's flags run past its data.");
            }
            $flag = ord($data[$at++]);
            $flags[] = $flag;
            if (($flag & 0x08) !== 0) {   // REPEAT_FLAG
                if ($at >= $length) {
                    throw new TypographyException("{$this->source->key()}: a glyph's flags run past its data.");
                }
                for ($repeat = ord($data[$at++]); $repeat > 0; $repeat--) {
                    $flags[] = $flag;
                }
            }
        }
        $flags = array_slice($flags, 0, $points);

        $xs = [];
        $value = 0;
        foreach ($flags as $flag) {
            if (($flag & 0x02) !== 0) {   // X_SHORT_VECTOR, X_IS_SAME_OR_POSITIVE gives the sign
                if ($at >= $length) {
                    throw new TypographyException("{$this->source->key()}: a glyph's coordinates run past its data.");
                }
                $byte = ord($data[$at++]);
                $value += ($flag & 0x10) !== 0 ? $byte : -$byte;
            } elseif (($flag & 0x10) === 0) {
                $value += OpenTypeFile::i16($data, $at);
                $at += 2;
            }
            $xs[] = $value;
        }
        $ys = [];
        $value = 0;
        foreach ($flags as $flag) {
            if (($flag & 0x04) !== 0) {   // Y_SHORT_VECTOR, Y_IS_SAME_OR_POSITIVE gives the sign
                if ($at >= $length) {
                    throw new TypographyException("{$this->source->key()}: a glyph's coordinates run past its data.");
                }
                $byte = ord($data[$at++]);
                $value += ($flag & 0x20) !== 0 ? $byte : -$byte;
            } elseif (($flag & 0x20) === 0) {
                $value += OpenTypeFile::i16($data, $at);
                $at += 2;
            }
            $ys[] = $value;
        }

        $contours = [];
        $first = 0;
        foreach ($ends as $last) {
            if ($last < $first || $last >= $points) {
                throw new TypographyException("{$this->source->key()}: a glyph's contour ends are out of order.");
            }
            $contour = [];
            for ($i = $first; $i <= $last; $i++) {
                $contour[] = [(float) $xs[$i], (float) $ys[$i], ($flags[$i] & 0x01) !== 0];
            }
            $contours[] = $contour;
            $first = $last + 1;
        }

        return $contours;
    }

    /**
     * A composite glyph's components: flags, glyph, the two arguments (an offset, or two point
     * numbers to match), and the transform [a, b, c, d] (x' = a x + c y, y' = b x + d y).
     *
     * @return list<array{int, int, int, int, float, float, float, float}>
     */
    private static function components(string $data): array
    {
        $components = [];
        $at = 10;
        do {
            $flags = OpenTypeFile::u16($data, $at);
            $glyph = OpenTypeFile::u16($data, $at + 2);
            $at += 4;
            $xy = ($flags & 0x0002) !== 0;   // ARGS_ARE_XY_VALUES: signed offsets, else point numbers
            if (($flags & 0x0001) !== 0) {   // ARG_1_AND_2_ARE_WORDS
                [$one, $two] = $xy
                    ? [OpenTypeFile::i16($data, $at), OpenTypeFile::i16($data, $at + 2)]
                    : [OpenTypeFile::u16($data, $at), OpenTypeFile::u16($data, $at + 2)];
                $at += 4;
            } else {
                if ($at + 2 > strlen($data)) {
                    throw new TypographyException('A composite glyph runs past its data.');
                }
                [$one, $two] = [ord($data[$at]), ord($data[$at + 1])];
                if ($xy) {
                    [$one, $two] = [$one >= 0x80 ? $one - 0x100 : $one, $two >= 0x80 ? $two - 0x100 : $two];
                }
                $at += 2;
            }
            [$a, $b, $c, $d] = [1.0, 0.0, 0.0, 1.0];
            if (($flags & 0x0008) !== 0) {   // WE_HAVE_A_SCALE
                $a = $d = OpenTypeFile::i16($data, $at) / 16384;
                $at += 2;
            } elseif (($flags & 0x0040) !== 0) {   // WE_HAVE_AN_X_AND_Y_SCALE
                [$a, $d] = [OpenTypeFile::i16($data, $at) / 16384, OpenTypeFile::i16($data, $at + 2) / 16384];
                $at += 4;
            } elseif (($flags & 0x0080) !== 0) {   // WE_HAVE_A_TWO_BY_TWO
                [$a, $b, $c, $d] = [
                    OpenTypeFile::i16($data, $at) / 16384, OpenTypeFile::i16($data, $at + 2) / 16384,
                    OpenTypeFile::i16($data, $at + 4) / 16384, OpenTypeFile::i16($data, $at + 6) / 16384,
                ];
                $at += 8;
            }
            $components[] = [$flags, $glyph, $one, $two, $a, $b, $c, $d];
        } while (($flags & 0x0020) !== 0);   // MORE_COMPONENTS

        return $components;
    }

    /**
     * TrueType contours as a path: quadratic curves, where two points off the curve in a row have an
     * implied point on it halfway between them.
     *
     * @param  list<list<array{float, float, bool}>>  $contours
     */
    private static function path(array $contours, float $across): Path
    {
        if ($across !== 0.0) {
            foreach ($contours as $c => $contour) {
                foreach ($contour as $p => $point) {
                    $contours[$c][$p][0] += $across;
                }
            }
        }
        $path = new Path();
        foreach ($contours as $contour) {
            $count = count($contour);
            if ($count === 0) {
                continue;
            }
            [$first, $last] = [$contour[0], $contour[$count - 1]];
            if ($first[2]) {
                $start = $first;
                $rest = array_slice($contour, 1);
            } elseif ($last[2]) {
                $start = $last;
                $rest = array_slice($contour, 0, $count - 1);
            } else {
                $start = [($first[0] + $last[0]) / 2, ($first[1] + $last[1]) / 2, true];
                $rest = $contour;
            }
            $rest[] = $start;
            $path->moveTo($start[0], $start[1]);
            $control = null;
            foreach ($rest as [$x, $y, $on]) {
                if ($on) {
                    is_null($control) ? $path->lineTo($x, $y) : $path->quadTo($control[0], $control[1], $x, $y);
                    $control = null;
                } elseif (is_null($control)) {
                    $control = [$x, $y];
                } else {
                    $path->quadTo($control[0], $control[1], ($control[0] + $x) / 2, ($control[1] + $y) / 2);
                    $control = [$x, $y];
                }
            }
            $path->close();
        }

        return $path;
    }
}
