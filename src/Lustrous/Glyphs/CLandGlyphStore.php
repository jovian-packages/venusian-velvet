<?php

namespace Jovian\Engines\Velvet\Lustrous\Glyphs;

use Jovian\Engines\Velvet\Lustrous\Rasterization\CLandScanner;
use RasterScanner;
use RasterSpanStore;
use Surface\Contracts\Rendering\Framebuffers\InstanceThatManagesAFramebuffer;
use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\Contracts\Rendering\Rasterization\Edges;
use Surface\Contracts\Rendering\RenderingException;
use VelvetGE\Contracts\Glyphs\GlyphStore;

/**
 * Lustrous's glyphs in C: each glyph scanned once by ext-rasterize and its spans kept in C
 * (RasterSpanStore); drawn straight into the frame's memory through ext-fb, every glyph of a draw in
 * one call. A glyph's coverage never becomes a PHP string: only its contours go in, once, and its
 * number and place each draw.
 */
final class CLandGlyphStore implements GlyphStore
{
    private RasterSpanStore $spans;

    /** @throws RenderingException When ext-rasterize has no RasterSpanStore, or cannot paint into ext-fb. */
    public function __construct(Edges $edges)
    {
        self::available() || throw new RenderingException('Lustrous\'s C glyphs need ext-rasterize with RasterSpanStore, and ext-fb loaded for it to paint into.');
        $this->spans = new RasterSpanStore($edges === Edges::ANTIALIASED);
    }

    /** Whether this PHP can keep and paint glyphs in C. */
    public static function available(): bool
    {
        return class_exists(RasterSpanStore::class, false) && RasterScanner::fills();
    }

    /** A frame whose bytes are in C memory ext-fb can blend into. */
    public function drawsInto(InstanceThatManagesAFramebuffer $target): bool
    {
        $memory = $target->memory();

        return ! is_null($memory) && ! is_null(CLandScanner::buffer($memory));
    }

    public function keep(array $contours, int $width, int $height): ?int
    {
        $number = $this->spans->add($contours, $width, $height);

        return $number < 0 ? null : $number;
    }

    public function forget(int $glyph): void
    {
        $this->spans->remove($glyph);
    }

    public function size(): int
    {
        return $this->spans->size();
    }

    public function paint(array $placements, int $rgba, Region $clip, InstanceThatManagesAFramebuffer $target): array
    {
        $memory = $target->memory();
        $painted = $this->spans->fill($placements, $rgba, CLandScanner::buffer($memory), $clip->x, $clip->y, $clip->width, $clip->height);

        return [is_null($painted) ? null : new Region(...$painted)];
    }
}
