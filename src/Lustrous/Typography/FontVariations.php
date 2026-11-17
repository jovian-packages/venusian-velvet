<?php

namespace Jovian\Engines\Velvet\Lustrous\Typography;

use Surface\Contracts\Typography\FontSource;
use Surface\Contracts\Typography\TypographyException;

/**
 * One instance of a variable font, as the OpenType font variations spec draws it: the source's
 * coordinates normalized against 'fvar' and mapped through 'avar', and the deltas 'gvar', 'HVAR' and
 * 'MVAR' give at them. Made once a source; the normalized coordinates, the shared tuples' scalars
 * and the variation stores' region scalars are worked out once, not a glyph at a time.
 *
 * Normalization follows the spec's precision rules: the user value in 16.16, the default
 * normalization and 'avar' in 16.16, each clamped to [-1, 1], then one conversion to 2.14.
 */
final class FontVariations
{
    /** @var list<float> normalized coordinates, fvar's axis order */
    public readonly array $coordinates;

    /** @var array{int, int, int, bool, int}|null gvar: shared tuple count, shared tuples offset, glyph count, long offsets, data array offset */
    private ?array $gvar = null;

    /** @var array<int, list<float>> shared tuple => its peak */
    private array $sharedTuples = [];

    /** @var array<int, float> shared tuple => its scalar (a peak alone: no intermediate region) */
    private array $sharedScalars = [];

    private ?ItemVariationStore $hvar = null;

    private ?string $hvarTable = null;

    private ?ItemVariationStore $mvar = null;

    /** @var array<string, array{int, int}> MVAR tag => delta-set index */
    private array $mvarRecords = [];

    /** @param  list<int>  $fixed  normalized coordinates in 2.14 */
    private function __construct(private readonly OpenTypeFile $file, private readonly string $key, array $fixed)
    {
        $this->coordinates = array_map(fn (int $c) => $c / 16384, $fixed);
        if ($file->has('gvar')) {
            $this->gvar = $this->gvarHeader();
        }
        $hvar = $file->table('HVAR');
        if (! is_null($hvar)) {
            if (OpenTypeFile::u16($hvar, 0) !== 1) {
                throw new TypographyException("{$key}'s 'HVAR' table is version ".OpenTypeFile::u16($hvar, 0).'; OpenType has version 1.');
            }
            $this->hvarTable = $hvar;
            $this->hvar = new ItemVariationStore(substr($hvar, OpenTypeFile::u32($hvar, 4)), $this->coordinates);
        }
        $mvar = $file->table('MVAR');
        if (! is_null($mvar)) {
            if (OpenTypeFile::u16($mvar, 0) !== 1) {
                throw new TypographyException("{$key}'s 'MVAR' table is version ".OpenTypeFile::u16($mvar, 0).'; OpenType has version 1.');
            }
            $size = OpenTypeFile::u16($mvar, 6);
            $count = OpenTypeFile::u16($mvar, 8);
            if ($count > 0) {
                if ($size < 8) {
                    throw new TypographyException("{$key}'s 'MVAR' records are {$size} bytes; each holds at least 8.");
                }
                for ($r = 0; $r < $count; $r++) {
                    $at = 12 + $size * $r;
                    $this->mvarRecords[substr($mvar, $at, 4)] = [OpenTypeFile::u16($mvar, $at + 4), OpenTypeFile::u16($mvar, $at + 6)];
                }
                $this->mvar = new ItemVariationStore(substr($mvar, OpenTypeFile::u16($mvar, 10)), $this->coordinates);
            }
        }
    }

    /**
     * The instance the source names, or null when there is nothing to vary: a source without
     * variations, a face without 'fvar' (its coordinates have nothing to apply to, so the face is
     * drawn as it is, as FreeType and HarfBuzz draw it), or coordinates that all normalize to the
     * default. An axis the face lacks is ignored; an axis the source does not give takes its default.
     *
     * @throws TypographyException When 'fvar' or 'avar' is broken or of a version OpenType does not have.
     */
    public static function of(OpenTypeFile $file, FontSource $source): ?self
    {
        if ($source->variations === [] || ! $file->has('fvar')) {
            return null;
        }
        $axes = self::axes($file->table('fvar'), $source->key());
        $fixed = [];
        foreach ($axes as $a => [$tag, $min, $default, $max]) {
            $value = $source->variations[$tag] ?? null;
            $fixed[$a] = is_null($value) ? 0 : self::normalized((int) floor($value * 65536 + 0.5), $min, $default, $max);
        }
        $avar = $file->table('avar');
        if (! is_null($avar)) {
            $fixed = self::mapped($avar, $fixed, $source->key());
        }
        $fixed = array_map(fn (int $v) => ($v + 2) >> 2, $fixed);   // 16.16 to 2.14, once
        if (array_filter($fixed) === []) {
            return null;
        }

        return new self($file, $source->key(), $fixed);
    }

    /**
     * The face's axes: tag, minimum, default and maximum, in 16.16.
     *
     * @return list<array{string, int, int, int}>
     *
     * @throws TypographyException When 'fvar' is not version 1, or its axes lie past it.
     */
    public static function axes(string $fvar, string $key): array
    {
        if (OpenTypeFile::u16($fvar, 0) !== 1) {
            throw new TypographyException("{$key}'s 'fvar' table is version ".OpenTypeFile::u16($fvar, 0).'; OpenType has version 1.');
        }
        $offset = OpenTypeFile::u16($fvar, 4);
        $count = OpenTypeFile::u16($fvar, 8);
        $size = OpenTypeFile::u16($fvar, 10);
        if ($size < 20) {
            throw new TypographyException("{$key}'s 'fvar' axis records are {$size} bytes; each holds 20.");
        }
        $instanceSize = OpenTypeFile::u16($fvar, 14);
        if ($instanceSize !== 4 * $count + 4 && $instanceSize !== 4 * $count + 6) {   // FreeType and HarfBuzz read the font as not variable
            throw new TypographyException("{$key}'s 'fvar' instance records are {$instanceSize} bytes; with {$count} axes OpenType has ".(4 * $count + 4)." or ".(4 * $count + 6).".");
        }
        $axes = [];
        for ($a = 0; $a < $count; $a++) {
            $at = $offset + $size * $a;
            [$min, $default, $max] = [self::fixed($fvar, $at + 4), self::fixed($fvar, $at + 8), self::fixed($fvar, $at + 12)];
            $axes[] = [substr($fvar, $at, 4), $min, $default, $max];
        }

        return $axes;
    }

    /**
     * The spec's tuple scalar: per axis 1 when the region is not valid for it or its peak is 0,
     * 0 when the instance is outside start..end, else the triangle between start, peak and end;
     * the product over the axes.
     *
     * @param  list<float>  $coordinates
     * @param  list<float>  $start
     * @param  list<float>  $peak
     * @param  list<float>  $end
     */
    public static function scalar(array $coordinates, array $start, array $peak, array $end): float
    {
        $scalar = 1.0;
        foreach ($peak as $a => $p) {
            [$s, $e, $c] = [$start[$a], $end[$a], $coordinates[$a]];
            if ($s > $p || $p > $e || ($s < 0 && $e > 0 && $p != 0) || $p == 0) {
                continue;
            }
            if ($c < $s || $c > $e) {
                return 0.0;
            }
            if ($c == $p) {
                continue;
            }
            $scalar *= $c < $p ? ($c - $s) / ($p - $s) : ($e - $c) / ($e - $p);
        }

        return $scalar;
    }

    /** MVAR's net adjustment for a value tag at the instance; 0 for a tag MVAR does not vary. */
    public function metric(string $tag): float
    {
        if (is_null($this->mvar) || ! isset($this->mvarRecords[$tag])) {
            return 0.0;
        }

        return $this->mvar->delta(...$this->mvarRecords[$tag]);
    }

    /** Whether the face has an 'HVAR' table, which then gives every advance's variation. */
    public function hasAdvances(): bool
    {
        return ! is_null($this->hvar);
    }

    /** HVAR's net adjustment to a glyph's advance: through its advance map, or the glyph as the inner index. */
    public function advance(int $glyph): float
    {
        if (is_null($this->hvar)) {
            return 0.0;
        }
        $map = OpenTypeFile::u32($this->hvarTable, 8);
        [$outer, $inner] = $map === 0 ? [0, $glyph] : ItemVariationStore::mapped($this->hvarTable, $map, $glyph);

        return $this->hvar->delta($outer, $inner);
    }

    /**
     * The 'gvar' deltas of one glyph's points at the instance: each tuple's deltas times its scalar,
     * summed. Points a tuple does not name take inferred deltas, contour by contour, when the glyph
     * is simple ($ends given); in a composite they take none.
     *
     * @param  list<array{float, float}>  $points  the glyph's points as they stand, its four phantom points last
     * @param  list<int>|null  $ends  a simple glyph's contour ends; null for a composite
     * @return list<array{float, float}>|null each point's delta; null when the glyph has no variation data
     *
     * @throws TypographyException When the glyph's variation data is broken.
     */
    public function glyph(int $glyph, array $points, ?array $ends): ?array
    {
        if (is_null($this->gvar)) {
            return null;
        }
        [$sharedCount, , $glyphCount, $long, $array] = $this->gvar;
        if ($glyph >= $glyphCount) {
            return null;
        }
        $offsets = $long
            ? unpack('N2', $this->file->part('gvar', 20 + 4 * $glyph, 8))
            : array_map(fn (int $o) => 2 * $o, unpack('n2', $this->file->part('gvar', 20 + 2 * $glyph, 4)));
        [$from, $to] = [$offsets[1], $offsets[2]];
        if ($to <= $from) {
            return null;
        }
        $data = $this->file->part('gvar', $array + $from, $to - $from);
        $axes = count($this->coordinates);
        $count = count($points);

        $header = OpenTypeFile::u16($data, 0);
        $tuples = $header & 0x0FFF;
        $at = OpenTypeFile::u16($data, 2);   // the serialized data
        $shared = null;
        if (($header & 0x8000) !== 0) {   // SHARED_POINT_NUMBERS
            [$shared, $at] = self::pointNumbers($data, $at);
        }
        $headerAt = 4;
        [$netX, $netY] = [array_fill(0, $count, 0.0), array_fill(0, $count, 0.0)];
        $xs = array_column($points, 0);
        $ys = array_column($points, 1);
        $any = false;
        for ($t = 0; $t < $tuples; $t++) {
            $size = OpenTypeFile::u16($data, $headerAt);
            $index = OpenTypeFile::u16($data, $headerAt + 2);
            $headerAt += 4;
            if (($index & 0x8000) !== 0) {   // EMBEDDED_PEAK_TUPLE
                $peak = self::tuple($data, $headerAt, $axes);
                $headerAt += 2 * $axes;
            } else {
                $shareIndex = $index & 0x0FFF;
                if ($shareIndex >= $sharedCount) {
                    throw new TypographyException("{$this->key}'s 'gvar' names shared tuple {$shareIndex}; it has {$sharedCount}.");
                }
                $peak = $this->sharedTuples[$shareIndex] ??= $this->sharedTuple($shareIndex);
            }
            if (($index & 0x4000) !== 0) {   // INTERMEDIATE_REGION
                $start = self::tuple($data, $headerAt, $axes);
                $end = self::tuple($data, $headerAt + 2 * $axes, $axes);
                $headerAt += 4 * $axes;
                $scalar = self::scalar($this->coordinates, $start, $peak, $end);
            } else {
                $scalar = ($index & 0x8000) === 0
                    ? ($this->sharedScalars[$index & 0x0FFF] ??= self::peakScalar($this->coordinates, $peak))
                    : self::peakScalar($this->coordinates, $peak);
            }
            $block = $at;
            $at += $size;
            if ($scalar == 0.0) {
                continue;
            }
            $named = $shared;
            if (($index & 0x2000) !== 0) {   // PRIVATE_POINT_NUMBERS
                [$named, $block] = self::pointNumbers($data, $block);
            }
            $n = is_null($named) ? $count : count($named);
            [$xDeltas, $block] = self::deltas($data, $block, $n, $at);   // x and y are two packed arrays,
            [$yDeltas] = self::deltas($data, $block, $n, $at);              // no run crosses between them
            $deltas = [...$xDeltas, ...$yDeltas];
            if (is_null($named)) {
                for ($i = 0; $i < $n; $i++) {
                    $netX[$i] += $scalar * $deltas[$i];
                    $netY[$i] += $scalar * $deltas[$n + $i];
                }
            } else {
                $dx = $dy = [];   // point => delta, for the points named
                foreach ($named as $i => $point) {
                    if ($point >= $count) {
                        throw new TypographyException("{$this->key}'s 'gvar' names point {$point} of a glyph with {$count}.");
                    }
                    [$dx[$point], $dy[$point]] = [$deltas[$i], $deltas[$n + $i]];
                }
                if (! is_null($ends)) {
                    [$dx, $dy] = self::inferred($xs, $ys, $ends, $dx, $dy);
                }
                foreach ($dx as $point => $delta) {
                    $netX[$point] += $scalar * $delta;
                    $netY[$point] += $scalar * $dy[$point];
                }
            }
            $any = true;
        }

        return $any ? array_map(null, $netX, $netY) : null;
    }

    /**
     * Deltas for the points a tuple does not name, per contour, from the named points around each
     * (in point order, wrapping in the contour), x and y apart, from the points' default positions:
     * the spec's inferred deltas. A contour with no named point keeps none.
     *
     * @param  list<float>  $xs  the points' default positions
     * @param  list<float>  $ys
     * @param  list<int>  $ends
     * @param  array<int, int>  $dx  named point => its delta
     * @param  array<int, int>  $dy
     * @return array{array<int, float>, array<int, float>}
     */
    private static function inferred(array $xs, array $ys, array $ends, array $dx, array $dy): array
    {
        $first = 0;
        foreach ($ends as $last) {
            $named = [];
            for ($i = $first; $i <= $last; $i++) {
                if (isset($dx[$i])) {
                    $named[] = $i;
                }
            }
            $k = count($named);
            if ($k > 0 && $k <= $last - $first) {
                for ($j = 0; $j < $k; $j++) {
                    // the run of unnamed points after named[j], up to the next named point (wrapping)
                    [$before, $after] = [$named[$j], $named[($j + 1) % $k]];
                    for ($i = $before + 1; ; $i++) {
                        if ($i > $last) {
                            $i = $first;
                        }
                        if ($i === $after) {
                            break;
                        }
                        $dx[$i] = self::infer($xs[$i], $xs[$before], $dx[$before], $xs[$after], $dx[$after]);
                        $dy[$i] = self::infer($ys[$i], $ys[$before], $dy[$before], $ys[$after], $dy[$after]);
                    }
                }
            }
            $first = $last + 1;
        }

        return [$dx, $dy];
    }

    /** The spec's inferred delta of one coordinate, from the named points before and after it. */
    private static function infer(float $target, float $before, float $beforeDelta, float $after, float $afterDelta): float
    {
        if ($before == $after) {
            return $beforeDelta == $afterDelta ? $beforeDelta : 0.0;
        }
        if ($target <= min($before, $after)) {
            return $before < $after ? $beforeDelta : $afterDelta;
        }
        if ($target >= max($before, $after)) {
            return $before > $after ? $beforeDelta : $afterDelta;
        }
        $proportion = ($target - $before) / ($after - $before);

        return (1 - $proportion) * $beforeDelta + $proportion * $afterDelta;
    }

    /**
     * Packed point numbers: a count (0: every point, the phantom points too), then runs of
     * differences from the point before, bytes or words.
     *
     * @return array{list<int>|null, int} the points (null: every point), and where the data goes on
     */
    private static function pointNumbers(string $data, int $at): array
    {
        $count = self::byte($data, $at++);
        if ($count === 0) {
            return [null, $at];
        }
        if (($count & 0x80) !== 0) {
            $count = (($count & 0x7F) << 8) | self::byte($data, $at++);
        }
        $points = [];
        $point = 0;
        while (count($points) < $count) {
            $control = self::byte($data, $at++);
            $words = ($control & 0x80) !== 0;
            for ($run = ($control & 0x7F) + 1; $run > 0 && count($points) < $count; $run--) {
                if ($words) {
                    $point += OpenTypeFile::u16($data, $at);
                    $at += 2;
                } else {
                    $point += self::byte($data, $at++);
                }
                $points[] = $point;
            }
        }

        return [$points, $at];
    }

    /**
     * One packed delta array: runs of zeros, of signed bytes or of signed words, until $count are
     * read; the deltas and where the array ends.
     *
     * @return array{list<int>, int}
     *
     * @throws TypographyException When they run past the tuple's data, a run sets both flags (not in
     *                             the OpenType spec), or a run holds more deltas than the array has
     *                             left (x and y are separate arrays; HarfBuzz refuses such a glyph).
     */
    private static function deltas(string $data, int $at, int $count, int $limit): array
    {
        $deltas = [];
        $have = 0;
        while ($have < $count) {
            if ($at >= $limit) {
                throw new TypographyException("A 'gvar' tuple's deltas run past its data.");
            }
            $control = self::byte($data, $at++);
            if (($control & 0xC0) === 0xC0) {
                throw new TypographyException("A 'gvar' delta run sets DELTAS_ARE_ZERO and DELTAS_ARE_WORDS together, which the OpenType spec does not define.");
            }
            $run = ($control & 0x3F) + 1;
            if ($run > $count - $have) {
                throw new TypographyException("A 'gvar' delta run holds {$run} deltas where its array has ".($count - $have)." left; x and y deltas are separate packed arrays.");
            }
            if (($control & 0x80) !== 0) {
                array_push($deltas, ...array_fill(0, $run, 0));
            } elseif (($control & 0x40) !== 0) {
                if ($at + 2 * $run > strlen($data)) {
                    throw new TypographyException("A 'gvar' glyph's variation data ends early.");
                }
                foreach (unpack('n*', substr($data, $at, 2 * $run)) as $word) {
                    $deltas[] = $word >= 0x8000 ? $word - 0x10000 : $word;
                }
                $at += 2 * $run;
            } else {
                if ($at + $run > strlen($data)) {
                    throw new TypographyException("A 'gvar' glyph's variation data ends early.");
                }
                array_push($deltas, ...unpack('c*', substr($data, $at, $run)));
                $at += $run;
            }
            $have += $run;
        }

        return [$deltas, $at];
    }

    /** A peak-only tuple's scalar: its region runs from 0 to the peak on each axis. */
    private static function peakScalar(array $coordinates, array $peak): float
    {
        $start = array_map(fn (float $p) => min($p, 0.0), $peak);
        $end = array_map(fn (float $p) => max($p, 0.0), $peak);

        return self::scalar($coordinates, $start, $peak, $end);
    }

    /** @return array{int, int, int, bool, int} */
    private function gvarHeader(): array
    {
        $header = $this->file->part('gvar', 0, 20);
        if (OpenTypeFile::u16($header, 0) !== 1) {
            throw new TypographyException("{$this->key}'s 'gvar' table is version ".OpenTypeFile::u16($header, 0).'; OpenType has version 1.');
        }
        if (OpenTypeFile::u16($header, 4) !== count($this->coordinates)) {
            throw new TypographyException("{$this->key}'s 'gvar' is for ".OpenTypeFile::u16($header, 4).' axes; its \'fvar\' has '.count($this->coordinates).'.');
        }

        return [
            OpenTypeFile::u16($header, 6), OpenTypeFile::u32($header, 8), OpenTypeFile::u16($header, 12),
            (OpenTypeFile::u16($header, 14) & 1) !== 0, OpenTypeFile::u32($header, 16),
        ];
    }

    /** @return list<float> */
    private function sharedTuple(int $index): array
    {
        $axes = count($this->coordinates);

        return self::tuple($this->file->part('gvar', $this->gvar[1] + 2 * $axes * $index, 2 * $axes), 0, $axes);
    }

    /** @return list<float> */
    private static function tuple(string $data, int $at, int $axes): array
    {
        $tuple = [];
        for ($a = 0; $a < $axes; $a++) {
            $tuple[] = OpenTypeFile::i16($data, $at + 2 * $a) / 16384;
        }

        return $tuple;
    }

    /**
     * The default normalization of a 16.16 user value, in 16.16: clamped to the axis, then -1..0
     * below the default and 0..1 above it (so already within the spec's [-1, 1] clamp).
     */
    private static function normalized(int $value, int $min, int $default, int $max): int
    {
        $value = max($min, min($max, $value));

        return match (true) {
            $value < $default => -self::divided($default - $value, $default - $min),
            $value > $default => self::divided($value - $default, $max - $default),
            default => 0,
        };
    }

    /**
     * Normalized coordinates through 'avar' version 1, in 16.16: each axis's segment map, linear
     * between its points. A map without the -1, 0 and 1 points changes nothing; a point that does
     * not move forward is passed over. A map kept runs from -1 to 1 without going back, so what it
     * gives is within the spec's [-1, 1] clamp.
     *
     * @param  list<int>  $fixed
     * @return list<int>
     *
     * @throws TypographyException When 'avar' is not version 1, or maps another number of axes.
     */
    private static function mapped(string $avar, array $fixed, string $key): array
    {
        if (OpenTypeFile::u16($avar, 0) !== 1) {
            throw new TypographyException("{$key}'s 'avar' table is version ".OpenTypeFile::u16($avar, 0).'; the OpenType spec has version 1.');
        }
        if (OpenTypeFile::u16($avar, 6) !== count($fixed)) {
            throw new TypographyException("{$key}'s 'avar' maps ".OpenTypeFile::u16($avar, 6).' axes; its \'fvar\' has '.count($fixed).'.');
        }
        $at = 8;
        foreach ($fixed as $a => $value) {
            $count = OpenTypeFile::u16($avar, $at);
            $maps = [];
            for ($m = 0; $m < $count; $m++) {
                [$from, $to] = [OpenTypeFile::i16($avar, $at + 2 + 4 * $m) << 2, OpenTypeFile::i16($avar, $at + 4 + 4 * $m) << 2];
                $last = end($maps);
                if ($last === false || ($from > $last[0] && $to >= $last[1])) {
                    $maps[] = [$from, $to];
                }
            }
            $at += 2 + 4 * $count;
            $points = array_column($maps, 1, 0);
            if (($points[-65536] ?? null) !== -65536 || ($points[0] ?? null) !== 0 || ($points[65536] ?? null) !== 65536) {
                continue;
            }
            // the first map point is -1, at or below every value: interpolate in the segment that
            // ends at or past the value, which gives a map point's own value exactly
            for ($m = 1; $maps[$m][0] < $value; $m++);
            [[$startFrom, $startTo], [$from, $to]] = [$maps[$m - 1], $maps[$m]];
            $fixed[$a] = $startTo + self::divided(($value - $startFrom) * ($to - $startTo), ($from - $startFrom) * 65536);
        }

        return $fixed;
    }

    /** a / b in 16.16, rounded to the nearest. */
    private static function divided(int $a, int $b): int
    {
        return (int) round($a * 65536 / $b);
    }

    private static function fixed(string $data, int $at): int
    {
        $value = OpenTypeFile::u32($data, $at);

        return $value >= 0x80000000 ? $value - 0x100000000 : $value;
    }

    private static function byte(string $data, int $at): int
    {
        return ord($data[$at] ?? throw new TypographyException("A 'gvar' glyph's variation data ends early."));
    }
}
