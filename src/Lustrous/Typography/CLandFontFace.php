<?php

namespace Jovian\Engines\Velvet\Lustrous\Typography;

use Surface\Contracts\Typography\FontFace;
use Surface\Contracts\Typography\FontMetrics;
use Surface\Contracts\Typography\FontSource;
use Surface\Contracts\Typography\TypographyException;
use Surface\NutsAndBolts\Path;
use TypogException;
use TypogFace;

/**
 * Lustrous's face in C: ext-typog reads the file and answers the numbers and the outline steps;
 * this turns them into Typography's FontMetrics and Paths. It reads a face by the same rules as the
 * PHP face (ext-typog's .okf writes them down), and refuses what it refuses: CFF2 outlines, a CFF
 * table it cannot read, a broken file. A variable font is drawn at the source's instance, by ext-typog's own reading of
 * 'fvar', 'avar', 'gvar', 'HVAR' and 'MVAR'.
 */
final class CLandFontFace implements FontFace
{
    /** Outlines kept, least recently used first out. */
    private const int KEPT = 1024;

    private readonly TypogFace $face;

    private readonly int $unitsPerEm;

    private readonly int $glyphCount;

    /** @var array{int, int, int} */
    private readonly array $vertical;

    /** @var array<int, Path> */
    private array $outlines = [];

    /** @throws TypographyException When the file is not a font, or lacks a table every face has. */
    public function __construct(private readonly FontSource $source)
    {
        try {
            $this->face = new TypogFace($source->path, $source->index, $source->variations);
            $this->unitsPerEm = $this->face->unitsPerEm();
            $this->glyphCount = $this->face->glyphCount();
            $this->vertical = $this->face->vertical();
            $this->face->glyph(0);   // the character map, read now as the PHP face reads it
        } catch (TypogException $e) {
            throw Typog::translated($e);
        }
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
        if ($codepoint < 0) {
            return 0;
        }
        try {
            $glyph = $this->face->glyph($codepoint);
        } catch (TypogException $e) {
            throw Typog::translated($e);
        }

        return $glyph < $this->glyphCount ? $glyph : 0;
    }

    public function advance(int $glyph): int
    {
        if ($glyph < 0) {
            return 0;
        }
        try {
            return $this->face->advance($glyph);
        } catch (TypogException $e) {
            throw Typog::translated($e);
        }
    }

    public function outline(int $glyph): Path
    {
        if (isset($this->outlines[$glyph])) {
            $outline = $this->outlines[$glyph];
            unset($this->outlines[$glyph]);

            return $this->outlines[$glyph] = $outline;
        }
        if ($glyph < 0) {
            throw new TypographyException("{$this->source->key()} has {$this->glyphCount} glyphs; there is no glyph {$glyph}.");
        }
        try {
            $steps = $this->face->outline($glyph);
        } catch (TypogException $e) {
            throw Typog::translated($e);
        }
        $path = new Path();
        foreach ($steps as $step) {
            match ($step[0]) {
                'M' => $path->moveTo($step[1], $step[2]),
                'L' => $path->lineTo($step[1], $step[2]),
                'Q' => $path->quadTo($step[1], $step[2], $step[3], $step[4]),
                'C' => $path->cubicTo($step[1], $step[2], $step[3], $step[4], $step[5], $step[6]),
                'Z' => $path->close(),
            };
        }
        if (count($this->outlines) >= self::KEPT) {
            unset($this->outlines[array_key_first($this->outlines)]);
        }

        return $this->outlines[$glyph] = $path;
    }

    /** Closes the file. */
    public function close(): void
    {
        $this->face->close();
        $this->outlines = [];
    }
}
