<?php

namespace Jovian\Engines\Velvet\Lustrous\Framebuffers;

use VelvetGE\Contracts\Framebuffers\TicketPixelStore;
use VelvetGE\Framebuffers\CompletingPixelStore;
use VelvetGE\Framebuffers\ImagePlacement;
use VelvetGE\Framebuffers\ReadsBlitSources;
use VelvetGE\Framebuffers\VelvetStores;
use Jovian\Engines\Velvet\Lustrous\Framebuffers\FullFramebuffer;
use Surface\Contracts\Graphics\Effects\FilterChain;
use VelvetGE\Contracts\Framebuffers\CompletePixelStore;
use Surface\Contracts\Rendering\Framebuffers\DamageGranularity;
use Surface\Contracts\Rendering\Framebuffers\Filter;
use Surface\Contracts\Rendering\Framebuffers\FormatSpec;
use Surface\Contracts\Rendering\Framebuffers\InstanceThatManagesAFramebuffer;
use VelvetGE\Contracts\Framebuffers\CompositingFramebuffer;
use VelvetGE\Contracts\Framebuffers\GradientFramebuffer;
use VelvetGE\Contracts\Framebuffers\ScrollingFramebuffer;
use VelvetGE\Contracts\Framebuffers\EffectsFramebuffer;
use Surface\Contracts\Rendering\Painting\PaintTarget;
use Surface\Contracts\Rendering\Framebuffers\FramebufferException;
use Surface\Contracts\Rendering\Framebuffers\PixelMemory;
use VelvetGE\Contracts\Framebuffers\PixelStore;
use VelvetGE\Contracts\Framebuffers\PixelStoreMinter;
use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\NutsAndBolts\Affine;
use Surface\NutsAndBolts\GradientSpread;
use Surface\Rendering\Framebuffers\FramebufferDelegate;

/**
 * Lustrous's framebuffer: InstanceThatManagesAFramebuffer and every optional contract, over one PixelStore. The minter says where the
 * store comes from; subclasses say what a write means (touched). A write outside
 * the surface is a caller bug and throws; setSegment() clips instead, since a
 * rect straddling the edge is ordinary.
 */
abstract class StoreFramebuffer extends FramebufferDelegate implements CompositingFramebuffer, GradientFramebuffer, ScrollingFramebuffer, EffectsFramebuffer, PaintTarget
{
    use ReadsBlitSources;

    protected CompletePixelStore $store;

    public function __construct(
        protected readonly PixelStoreMinter $minter,
        protected FormatSpec $format,
        protected int $width,
        protected int $height,
    ) {
        // A store that leaves a tier out is completed here, once: every kind then draws through all of them.
        $store = $minter->mint($format, $width, $height);
        $this->store = $store instanceof CompletePixelStore ? $store : new CompletingPixelStore($store, new VelvetStores());
    }

    public function store(): CompletePixelStore
    {
        return $this->store;
    }

    public function viewportWidth(): int
    {
        return $this->width;
    }

    public function viewportHeight(): int
    {
        return $this->height;
    }

    public function hostFormat(): FormatSpec
    {
        return $this->format;
    }

    public function getPixel(int $x, int $y): int
    {
        $this->guard($x, $y);

        return $this->store->get($x, $y);
    }

    public function setPixel(int $x, int $y, int $value): static
    {
        $this->guard($x, $y);
        $this->store->set($x, $y, $value);
        $this->touched(new Region($x, $y, 1, 1));

        return $this;
    }

    public function setPixels(array $pixels): static
    {
        return $this->wrote($this->store->setPixels($pixels));
    }

    public function setRegion(array $coordinates, int $value): static
    {
        return $this->wrote($this->store->setCoordinates($coordinates, $value));
    }

    public function setSegment(int $x, int $y, int $width, int $height, int $color): static
    {
        $region = (new Region($x, $y, $width, $height))->intersect(Region::wholeSurface($this->width, $this->height));
        if (is_null($region)) {
            return $this;
        }
        $this->store->rect($region, $color);

        return $this->wrote($region);
    }

    public function paintSpans(string $spans, int $rgba8): static
    {
        return $this->wrote($this->store->paintSpans($spans, $rgba8));
    }

    public function kawaseDown(InstanceThatManagesAFramebuffer $source, Region $region): static
    {
        $this->store->kawaseDown(self::storeOf($source), $region);

        return $this;
    }

    public function kawaseUp(InstanceThatManagesAFramebuffer $source): static
    {
        $this->store->kawaseUp(self::storeOf($source));

        return $this;
    }

    public function paintGlass(array $geometry, InstanceThatManagesAFramebuffer $blur, int $scale, ?InstanceThatManagesAFramebuffer $sharp, int $originX, int $originY, array $look, Region $clip): static
    {
        return $this->wrote($this->store->paintGlass($geometry, self::storeOf($blur), $scale, $sharp === null ? null : self::storeOf($sharp), $originX, $originY, $look, $clip));
    }

    /** The store of a glass level: a frame of this kind. */
    private static function storeOf(InstanceThatManagesAFramebuffer $frame): PixelStore
    {
        return $frame instanceof self ? $frame->store : throw new FramebufferException('Glass levels are frames of a store-backed framebuffer.');
    }

    public function memory(): ?PixelMemory
    {
        return $this->store->memory();
    }

    public function drewInto(?Region ...$regions): static
    {
        if (is_null($this->store->memory())) {
            throw new FramebufferException('This framebuffer has no memory to have been written into.');
        }
        foreach ($regions as $region) {
            $this->wrote($region);
        }

        return $this;
    }

    public function paintMasks(array $masks, int $rgba8, Region $clip): static
    {
        return $this->wrote($this->store->paintMasks($masks, $rgba8, $clip));
    }

    public function paintImage(InstanceThatManagesAFramebuffer $source, Affine $placement, int $opacity = 255, Filter $filter = Filter::NEAREST, ?Region $clip = null, int $tint = 0xFFFFFFFF, ?array $window = null, ?string $coverage = null): static
    {
        // The source's ticket, when the store's library can read it: nothing comes to PHP.
        $memory = $this->ticket($source);
        if (! is_null($memory)) {
            $plan = ImagePlacement::plan($placement, $memory->width, $memory->height, 0, Region::wholeSurface($this->width, $this->height), $clip, $opacity, $window);
            if (is_null($plan)) {
                return $this;
            }
            [$target, $inverse, $held] = $plan;
            $this->store->paintMemory($memory, $inverse, $target, $opacity, $filter, 0, $tint, $held, $coverage);

            return $this->wrote($target);
        }
        [$rgba8, $width, $height, $top] = $this->blitSource($source);
        $plan = ImagePlacement::plan($placement, $width, $height, $top, Region::wholeSurface($this->width, $this->height), $clip, $opacity, $window);
        if (is_null($plan)) {
            return $this;
        }
        [$target, $inverse, $held] = $plan;
        $this->store->paintRgba8($rgba8, $width, $height, $inverse, $target, $opacity, $filter, 0, $tint, $held, $coverage);

        return $this->wrote($target);
    }

    /** $source's ticket when this store's library can read it where it is; null for the RGBA8 read-back. */
    private function ticket(InstanceThatManagesAFramebuffer $source): ?PixelMemory
    {
        if (! $this->store instanceof TicketPixelStore) {
            return null;
        }
        $memory = $this->sourceMemory($source);

        return ! is_null($memory) && $this->store->takes($memory) ? $memory : null;
    }

    public function scratch(int $width, int $height): InstanceThatManagesAFramebuffer
    {
        // A blank RGBA8 frame from the same minter: its store is this frame's kind of store.
        return new FullFramebuffer($this->minter, FormatSpec::rgba8(), $width, $height);
    }

    public function filter(FilterChain $chain, ?Region $region = null, bool $premultiplied = false): static
    {
        $region = Region::wholeSurface($this->width, $this->height)->intersect($region ?? Region::wholeSurface($this->width, $this->height));
        if (! is_null($region)) {
            $this->store->filter($chain, $region, 0, $premultiplied);
            $this->wrote($region);
        }

        return $this;
    }

    public function paintGradient(string $spans, string $ramp, string $kind, GradientSpread $spread, array $inverse): static
    {
        return $this->wrote($this->store->paintGradient($spans, $ramp, $kind, $spread, $inverse));
    }

    public function clear(): static
    {
        return $this->fill(0);
    }

    public function fill(int $color): static
    {
        $this->store->fill($color);

        return $this->wrote(Region::wholeSurface($this->width, $this->height));
    }

    public function blitTo(InstanceThatManagesAFramebuffer $target, int $offset_x = 0, int $offset_y = 0): InstanceThatManagesAFramebuffer
    {
        return $target->blitFrom($this, $offset_x, $offset_y);
    }

    public function blitFrom(InstanceThatManagesAFramebuffer $source, int $offset_x = 0, int $offset_y = 0): InstanceThatManagesAFramebuffer
    {
        $memory = $this->ticket($source);
        if (! is_null($memory)) {
            return $this->wrote($this->store->blitMemory($memory, Region::wholeSurface($memory->width, $memory->height), $offset_x, $offset_y));
        }
        [$rgba8, $width, $height, $top] = $this->blitSource($source);

        return $this->writeRgba8($rgba8, $width, $height, $offset_x, $offset_y + $top);
    }

    public function writeRgba8(string $rgba8, int $width, int $height, int $x = 0, int $y = 0): static
    {
        return $this->wrote($this->store->blitRgba8($rgba8, $width, $height, $x, $y));
    }

    public function scroll(Region $region, int $dx, int $dy): void
    {
        $clip = $region->intersect(new Region(0, 0, $this->width, $this->height));
        $source = is_null($clip) ? null : self::scrollSource($clip, $dx, $dy);
        if (is_null($source)) {
            return;
        }
        if ($this->store instanceof TicketPixelStore) {
            $this->store->shift($source, $dx, $dy);
        } else {
            // The read is a copy: the write may overlap it.
            $this->store->blitRgba8($this->store->region($source, FormatSpec::rgba8()), $source->width, $source->height, $source->x + $dx, $source->y + $dy);
        }
        $this->touched($clip);
    }

    public function dump(?int $layer = null): string
    {
        return $this->store->bytes($layer);
    }

    public function flush(FormatSpec $spec, bool $as_array = false): string|array
    {
        return self::answer($this->store->region(Region::wholeSurface($this->width, $this->height), $spec), $as_array);
    }

    public function flushRegion(Region $region, FormatSpec $spec, bool $as_array = false): string|array
    {
        if ($region->isEmpty() || $region->x < 0 || $region->y < 0 || $region->right() > $this->width || $region->bottom() > $this->height) {
            throw FramebufferException::outsideSurface($region, $this->width, $this->height);
        }

        return self::answer($this->store->region($region, $spec), $as_array);
    }

    public function toRgba8(): string
    {
        return $this->store->toRgba8();
    }

    public function pointer(): int
    {
        return $this->store->pointer();
    }

    public function damageGranularity(): DamageGranularity
    {
        return $this->store->granularity();
    }

    public function preservesContentsOnPresent(): bool
    {
        return true;
    }
}
