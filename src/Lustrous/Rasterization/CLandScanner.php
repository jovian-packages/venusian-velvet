<?php

namespace Jovian\Engines\Velvet\Lustrous\Rasterization;

use FbBuffer;
use RasterScanner;
use Surface\Contracts\Rendering\Framebuffers\FramebufferException;
use Surface\Contracts\Rendering\Framebuffers\PixelMemory;
use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\Contracts\Rendering\Rasterization\CompleteScanner;
use Surface\Contracts\Rendering\Rasterization\DirectScanner;
use Surface\Contracts\Rendering\Rasterization\Edges;
use Surface\Contracts\Rendering\Rasterization\FillRule;
use Surface\Contracts\Rendering\Rasterization\RasterizeException;
use ValueError;
use VelvetGE\Framebuffers\C\FbFormats;

class CLandScanner implements CompleteScanner, DirectScanner
{
    private RasterScanner $scanner;

    public function __construct(
        private readonly Region $clip,
        private readonly Edges $edges,
    ) {
        try {
            $this->scanner = new RasterScanner($clip->x, $clip->y, $clip->width, $clip->height, $edges === Edges::ANTIALIASED);
        } catch (ValueError $e) {
            throw new RasterizeException($e->getMessage(), previous: $e);
        }
    }

    public function clip(): Region
    {
        return $this->clip;
    }

    public function edges(): Edges
    {
        return $this->edges;
    }

    public function path(array $contours, FillRule $rule): string
    {
        try {
            return $this->scanner->path($contours, $rule === FillRule::EVEN_ODD ? \RASTER_EVEN_ODD : \RASTER_NON_ZERO);
        } catch (ValueError $e) {
            throw new RasterizeException($e->getMessage(), previous: $e);
        }
    }

    public function ellipse(float $cx, float $cy, float $rx, float $ry): string
    {
        try {
            return $this->scanner->ellipse($cx, $cy, $rx, $ry);
        } catch (ValueError $e) {
            throw new RasterizeException($e->getMessage(), previous: $e);
        }
    }

    public function ring(float $cx, float $cy, float $rx, float $ry, float $stroke): string
    {
        try {
            return $this->scanner->ring($cx, $cy, $rx, $ry, $stroke);
        } catch (ValueError $e) {
            throw new RasterizeException($e->getMessage(), previous: $e);
        }
    }

    public function roundRect(float $x0, float $y0, float $x1, float $y1, array $radii): string
    {
        try {
            return $this->scanner->roundRect($x0, $y0, $x1, $y1, $radii);
        } catch (ValueError $e) {
            throw new RasterizeException($e->getMessage(), previous: $e);
        }
    }

    public function roundRing(float $x0, float $y0, float $x1, float $y1, array $radii, float $inset): string
    {
        try {
            return $this->scanner->roundRing($x0, $y0, $x1, $y1, $radii, $inset);
        } catch (ValueError $e) {
            throw new RasterizeException($e->getMessage(), previous: $e);
        }
    }

    public function shadow(float $x0, float $y0, float $x1, float $y1, float $radius, float $blur): string
    {
        try {
            return $this->scanner->shadow($x0, $y0, $x1, $y1, $radius, $blur);
        } catch (ValueError $e) {
            throw new RasterizeException($e->getMessage(), previous: $e);
        }
    }

    public function polyline(array $points, bool $closed): string
    {
        try {
            return $this->scanner->polyline($points, $closed);
        } catch (ValueError $e) {
            throw new RasterizeException($e->getMessage(), previous: $e);
        }
    }

    public function batch(array $ops): array
    {
        try {
            return $this->scanner->batch($ops);
        } catch (ValueError $e) {
            throw new RasterizeException($e->getMessage(), previous: $e);
        }
    }

    /**
     * Any memory ext-fb can blend into: a format ext-fb has, rows packed or padded (FbBuffer::over()'s stride). That is
     * ext-fb's own store, and a library's store that keeps its pixels that way.
     */
    public function drawsInto(PixelMemory $memory): bool
    {
        return RasterScanner::fills() && ! is_null(self::buffer($memory));
    }

    /** batch()'s ops painted in C through ext-fb's span sink, each at its colour, blended as ext-fb blends. */
    public function fill(array $ops, array $colours, PixelMemory $memory): array
    {
        try {
            return array_map(fn (?array $r): ?Region => $r === null ? null : new Region(...$r), $this->scanner->fill($ops, $colours, self::buffer($memory)));
        } catch (ValueError $e) {
            throw new RasterizeException($e->getMessage(), previous: $e);
        }
    }

    /** The FbBuffer whose bytes these are: the one that owns them, or one standing over another library's. Null when ext-fb cannot. */
    public static function buffer(PixelMemory $memory): ?FbBuffer
    {
        $owner = $memory->owner;
        // The owner itself when it is the buffer these bytes are, shape and all: an FbBuffer at the
        // same address but of another size or row stride is only the memory's keeper.
        if ($owner instanceof FbBuffer && $owner->pointer() === $memory->address
                && $owner->width() === $memory->width && $owner->height() === $memory->height
                && $owner->size() === $memory->stride * $memory->height) {
            return $owner;
        }
        if (! method_exists(FbBuffer::class, 'over')) {
            return null;    // an ext-fb from before FbBuffer::over(): it draws into its own buffers only
        }
        try {
            $format = FbFormats::from($memory->format);
        } catch (FramebufferException) {
            return null;    // a layout ext-fb does not have
        }
        $packed = intdiv($format->size($memory->width, $memory->height), max(1, $memory->height));
        if ($memory->stride === $packed) {
            return FbBuffer::over($format, $memory->width, $memory->height, $memory->address, $owner);
        }
        // Padded rows (a library that aligns its rows): ext-fb takes the stride for formats with
        // whole-byte pixels, and refuses it, with a ValueError, for any other.
        try {
            return FbBuffer::over($format, $memory->width, $memory->height, $memory->address, $owner, $memory->stride);
        } catch (\ValueError|\ArgumentCountError) {
            return null;    // a layout ext-fb packs bit by bit, or an ext-fb from before the stride
        }
    }
}
