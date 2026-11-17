<?php

namespace Jovian\Engines\Velvet\Lustrous\Framebuffers;

use Surface\Contracts\Rendering\Framebuffers\FormatSpec;
use Surface\Contracts\Rendering\Framebuffers\FramebufferException;
use Surface\Contracts\Rendering\Framebuffers\InstanceThatManagesAFramebuffer;
use Surface\Rendering\Framebuffers\FramebufferDriver;
use VelvetGE\Contracts\Framebuffers\PixelStoreMinter;

/**
 * Lustrous's framebuffers: the five kinds over one kind of PixelStore. A driver says its module's
 * name and mints its store; the kinds do the damage, the pages and the frames.
 *
 * make() gives one kind. A driver registered under the module's name ('lustrous-php', 'lustrous-c')
 * gives the configured default kind; one registered under a kind's own name ('lustrous-c-ring')
 * always gives that kind. Paged and ring frames take their page height and frame count from here,
 * as configured; the kind methods take them as arguments for a caller that says them itself.
 *
 * The store needs only the core, PixelStore: hold the pixels, read and write them, move them.
 * That alone is a whole framebuffer, because a kind completes the store it is minted: painting
 * and effects then run with Lustrous's arithmetic over the store's pixels. A store adds
 * PaintingPixelStore or EffectsPixelStore for what its library does itself, and those calls go
 * straight to it.
 */
abstract class StoreFramebufferDriver extends FramebufferDriver implements PixelStoreMinter
{
    /** The kinds make() can give, by name. */
    public const array KINDS = ['full', 'dirty', 'epaper', 'paged', 'ring'];

    /**
     * @param  string|null  $kind  The kind every make() gives, named by the driver ('lustrous-c-ring');
     *                             null to give $default_kind under the module's own name.
     * @param  int  $page_rows  Rows per page of a paged frame; 0 for one page of the whole height.
     * @param  int  $frames  Frames in a ring.
     *
     * @throws FramebufferException For a kind that is not one of KINDS.
     */
    public function __construct(
        protected readonly ?string $kind = null,
        protected readonly string $default_kind = 'full',
        protected readonly int $page_rows = 0,
        protected readonly int $frames = 2,
    ) {
        foreach ([$kind, $default_kind] as $name) {
            if (! is_null($name) && ! in_array($name, self::KINDS, true)) {
                throw new FramebufferException("A Lustrous framebuffer kind is one of '".implode("', '", self::KINDS)."', got '{$name}'.");
            }
        }
    }

    /** The module's name: 'lustrous-php' or 'lustrous-c'. */
    abstract protected function module(): string;

    public function driver(): string
    {
        return is_null($this->kind) ? $this->module() : "{$this->module()}-{$this->kind}";
    }

    public function make(FormatSpec $format, int $width, int $height): InstanceThatManagesAFramebuffer
    {
        return match ($this->kind ?? $this->default_kind) {
            'full' => $this->full($format, $width, $height),
            'dirty' => $this->dirty($format, $width, $height),
            'epaper' => $this->epaper($format, $width, $height),
            'paged' => $this->paged($format, $width, $height, $this->page_rows > 0 ? $this->page_rows : $height),
            'ring' => $this->ring($format, $width, $height, $this->frames),
        };
    }

    public function full(FormatSpec $format, int $width, int $height): FullFramebuffer
    {
        return new FullFramebuffer($this, $format, $width, $height);
    }

    public function dirty(FormatSpec $format, int $width, int $height): DirtyFramebuffer
    {
        return new DirtyFramebuffer($this, $format, $width, $height);
    }

    public function epaper(FormatSpec $format, int $width, int $height): ePaperFramebuffer
    {
        return new ePaperFramebuffer($this, $format, $width, $height);
    }

    public function paged(FormatSpec $format, int $width, int $height, int $page_rows): PagedFramebuffer
    {
        return new PagedFramebuffer($this, $format, $width, $height, $page_rows);
    }

    public function ring(FormatSpec $format, int $width, int $height, int $frames): RingFramebuffer
    {
        return new RingFramebuffer($this, $format, $width, $height, $frames);
    }
}
