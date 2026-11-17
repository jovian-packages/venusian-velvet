<?php

namespace Jovian\Engines\Velvet\Lustrous\Glyphs;

use Jovian\Engines\Velvet\Lustrous\Rasterization\PHPLandScanner;
use Surface\Contracts\Rendering\Framebuffers\InstanceThatManagesAFramebuffer;
use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\Contracts\Rendering\Framebuffers\Spans;
use Surface\Contracts\Rendering\Rasterization\Edges;
use Surface\Contracts\Rendering\Rasterization\FillRule;
use VelvetGE\Contracts\Framebuffers\CompositingFramebuffer;
use VelvetGE\Contracts\Glyphs\GlyphStore;

/**
 * Lustrous's glyphs in PHP: each glyph scanned once by Lustrous's PHP scanner and kept as a coverage
 * mask, its rows of bytes; drawn by the framebuffer's paintMasks(), every glyph of a draw in one call.
 * Its glyphs are PHP strings: this is the store for frames whose pixels PHP holds.
 */
final class PHPLandGlyphStore implements GlyphStore
{
    /** @var array<int, array{string, int}> number => [coverage rows, width] */
    private array $masks = [];

    /** @var list<int> */
    private array $spare = [];

    private int $size = 0;

    public function __construct(
        private readonly Edges $edges,
    ) {}

    /** Any framebuffer that paints coverage masks. */
    public function drawsInto(InstanceThatManagesAFramebuffer $target): bool
    {
        return $target instanceof CompositingFramebuffer;
    }

    public function keep(array $contours, int $width, int $height): ?int
    {
        $spans = (new PHPLandScanner(new Region(0, 0, $width, $height), $this->edges))->path($contours, FillRule::NON_ZERO);
        if ($spans === '') {
            return null;
        }
        $mask = str_repeat("\0", $width * $height);
        foreach (Spans::unpack($spans) as [$y, $x, $length, $coverage]) {
            $mask = substr_replace($mask, str_repeat(chr($coverage), $length), $y * $width + $x, $length);
        }
        $number = array_pop($this->spare) ?? count($this->masks);
        $this->masks[$number] = [$mask, $width];
        $this->size += strlen($mask);

        return $number;
    }

    public function forget(int $glyph): void
    {
        $this->size -= strlen($this->masks[$glyph][0]);
        unset($this->masks[$glyph]);
        $this->spare[] = $glyph;
    }

    public function size(): int
    {
        return $this->size;
    }

    /** @param  CompositingFramebuffer  $target */
    public function paint(array $placements, int $rgba, Region $clip, InstanceThatManagesAFramebuffer $target): array
    {
        $masks = [];
        for ($i = 0, $n = count($placements); $i < $n; $i += 3) {
            [$mask, $width] = $this->masks[$placements[$i]];
            $masks[] = [$mask, $width, $placements[$i + 1], $placements[$i + 2]];
        }
        // the framebuffer records what it painted
        $target->paintMasks($masks, $rgba, $clip);

        return [];
    }
}
