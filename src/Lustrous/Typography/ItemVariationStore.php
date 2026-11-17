<?php

namespace Jovian\Engines\Velvet\Lustrous\Typography;

use Surface\Contracts\Typography\TypographyException;

/**
 * An OpenType ItemVariationStore (the common format HVAR and MVAR keep their deltas in) at one
 * instance: a delta set's net adjustment, each region's scalar worked out once, the first time a
 * delta set uses it. Also reads a DeltaSetIndexMap.
 */
final class ItemVariationStore
{
    /** @var list<array{list<float>, list<float>, list<float>}> each region's start, peak and end per axis */
    private readonly array $regions;

    /** @var list<int> each ItemVariationData subtable's offset in the store; 0 for none */
    private readonly array $subtables;

    /** @var array<int, float> region => its scalar at the instance */
    private array $scalars = [];

    /** @var array<int, array{int, int, bool, list<int>, int}|null> subtable => item count, word deltas, long words, region indexes, rows' offset */
    private array $read = [];

    /**
     * @param  string  $data  the store, from its first byte to the end of the table holding it
     * @param  list<float>  $coordinates  the instance's normalized coordinates, in fvar's axis order
     *
     * @throws TypographyException When the store is not format 1, or its regions are for another
     *                             number of axes.
     */
    public function __construct(private readonly string $data, private readonly array $coordinates)
    {
        if (OpenTypeFile::u16($data, 0) !== 1) {
            throw new TypographyException('An ItemVariationStore is format '.OpenTypeFile::u16($data, 0).'; the OpenType format is 1.');
        }
        $list = OpenTypeFile::u32($data, 2);
        $count = OpenTypeFile::u16($data, 6);
        $subtables = [];
        for ($i = 0; $i < $count; $i++) {
            $subtables[] = OpenTypeFile::u32($data, 8 + 4 * $i);
        }
        $this->subtables = $subtables;

        $axes = OpenTypeFile::u16($data, $list);
        if ($axes !== count($coordinates)) {
            throw new TypographyException("An ItemVariationStore's regions are for {$axes} axes; the font has ".count($coordinates).'.');
        }
        $regions = [];
        $at = $list + 4;
        for ($r = OpenTypeFile::u16($data, $list + 2); $r > 0; $r--) {
            [$start, $peak, $end] = [[], [], []];
            for ($a = 0; $a < $axes; $a++, $at += 6) {
                $start[] = OpenTypeFile::i16($data, $at) / 16384;
                $peak[] = OpenTypeFile::i16($data, $at + 2) / 16384;
                $end[] = OpenTypeFile::i16($data, $at + 4) / 16384;
            }
            $regions[] = [$start, $peak, $end];
        }
        $this->regions = $regions;
    }

    /**
     * A delta set's net adjustment at the instance: each of its deltas times its region's scalar,
     * summed. 0 for the "no variation data" index (0xFFFF, 0xFFFF), an absent subtable or a row past
     * its subtable's items.
     *
     * @throws TypographyException When a delta set names a region the store does not have.
     */
    public function delta(int $outer, int $inner): float
    {
        if ($outer >= count($this->subtables) || $this->subtables[$outer] === 0) {
            return 0.0;
        }
        [$items, $words, $long, $indexes, $rows] = $this->read[$outer] ??= $this->subtable($this->subtables[$outer]);
        if ($inner >= $items) {
            return 0.0;
        }
        $count = count($indexes);
        [$big, $small] = $long ? [4, 2] : [2, 1];
        $at = $rows + $inner * ($big * $words + $small * ($count - $words));
        $net = 0.0;
        for ($k = 0; $k < $count; $k++) {
            if ($k < $words) {
                $delta = $long ? self::i32($this->data, $at) : OpenTypeFile::i16($this->data, $at);
                $at += $big;
            } else {
                $delta = $long ? OpenTypeFile::i16($this->data, $at) : self::i8($this->data, $at);
                $at += $small;
            }
            if ($delta !== 0) {
                $net += $delta * $this->scalar($indexes[$k]);
            }
        }

        return $net;
    }

    /**
     * The (outer, inner) delta-set index a DeltaSetIndexMap gives an item: an index past the map
     * takes its last entry.
     *
     * @param  string  $data  the table holding the map
     * @return array{int, int}
     *
     * @throws TypographyException When the map is not format 0 or 1, or is empty.
     */
    public static function mapped(string $data, int $offset, int $index): array
    {
        $format = ord($data[$offset] ?? throw new TypographyException('A DeltaSetIndexMap lies past its table.'));
        $entryFormat = ord($data[$offset + 1] ?? "\0");
        [$count, $at] = match ($format) {
            0 => [OpenTypeFile::u16($data, $offset + 2), $offset + 4],
            1 => [OpenTypeFile::u32($data, $offset + 2), $offset + 6],
            default => throw new TypographyException("A DeltaSetIndexMap is format {$format}; OpenType has 0 and 1."),
        };
        if ($count === 0) {
            throw new TypographyException('A DeltaSetIndexMap has no entries.');
        }
        $size = (($entryFormat & 0x30) >> 4) + 1;
        $innerBits = ($entryFormat & 0x0F) + 1;
        $at += $size * min($index, $count - 1);
        $entry = 0;
        for ($b = 0; $b < $size; $b++) {
            $entry = ($entry << 8) | ord($data[$at + $b] ?? throw new TypographyException('A DeltaSetIndexMap ends before its entries.'));
        }

        return [$entry >> $innerBits, $entry & ((1 << $innerBits) - 1)];
    }

    /** A region's scalar at the instance, worked out once. */
    private function scalar(int $region): float
    {
        if (! isset($this->scalars[$region])) {
            if (! isset($this->regions[$region])) {
                throw new TypographyException("An ItemVariationStore's delta set names region {$region}; it has ".count($this->regions).'.');
            }
            [$start, $peak, $end] = $this->regions[$region];
            $this->scalars[$region] = FontVariations::scalar($this->coordinates, $start, $peak, $end);
        }

        return $this->scalars[$region];
    }

    /** @return array{int, int, bool, list<int>, int} */
    private function subtable(int $at): array
    {
        $items = OpenTypeFile::u16($this->data, $at);
        $wordCount = OpenTypeFile::u16($this->data, $at + 2);
        $count = OpenTypeFile::u16($this->data, $at + 4);
        $indexes = [];
        for ($k = 0; $k < $count; $k++) {
            $indexes[] = OpenTypeFile::u16($this->data, $at + 6 + 2 * $k);
        }
        $words = $wordCount & 0x7FFF;
        if ($words > $count) {
            throw new TypographyException("An ItemVariationData has {$words} word deltas a row and {$count} regions.");
        }
        $long = ($wordCount & 0x8000) !== 0;
        $rows = $at + 6 + 2 * $count;
        $row = ($long ? 4 * $words + 2 * ($count - $words) : 2 * $words + ($count - $words));
        if ($rows + $items * $row > strlen($this->data)) {
            throw new TypographyException('An ItemVariationData holds fewer rows than it says.');
        }

        return [$items, $words, $long, $indexes, $rows];
    }

    private static function i8(string $data, int $at): int
    {
        $byte = ord($data[$at] ?? throw new TypographyException('A font table ends before a value it should hold.'));

        return $byte >= 0x80 ? $byte - 0x100 : $byte;
    }

    private static function i32(string $data, int $at): int
    {
        $value = OpenTypeFile::u32($data, $at);

        return $value >= 0x80000000 ? $value - 0x100000000 : $value;
    }
}
