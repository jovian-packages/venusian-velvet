<?php

namespace Jovian\Engines\Velvet\Lustrous\Glyphs;

use Surface\Contracts\Rendering\Framebuffers\InstanceThatManagesAFramebuffer;
use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\Contracts\Rendering\Glyphs\GlyphPainter;
use VelvetGE\Glyphs\GlyphCache;

/** Lustrous's glyphs in C where they can draw into the frame, in PHP otherwise: each keeps its own. */
final class AutoGlyphs implements GlyphPainter
{
    public function __construct(
        public readonly ?GlyphCache $c,
        public readonly GlyphCache $php,
    ) {}

    public function drawsInto(InstanceThatManagesAFramebuffer $target): bool
    {
        return $this->for($target)->drawsInto($target);
    }

    public function draw(array $command, Region $clip, InstanceThatManagesAFramebuffer $target): array
    {
        return $this->for($target)->draw($command, $clip, $target);
    }

    private function for(InstanceThatManagesAFramebuffer $target): GlyphCache
    {
        return ! is_null($this->c) && $this->c->drawsInto($target) ? $this->c : $this->php;
    }
}
