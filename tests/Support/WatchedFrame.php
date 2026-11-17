<?php

use Surface\Contracts\Rendering\Framebuffers\DamageGranularity;
use Surface\Contracts\Rendering\Framebuffers\FormatSpec;
use Surface\Contracts\Rendering\Framebuffers\InstanceThatManagesAFramebuffer;
use Surface\Contracts\Rendering\Framebuffers\PixelMemory;
use Surface\Contracts\Rendering\Framebuffers\Region;

/*
 * Shared by every framebuffer module's tests: a frame whose read-backs are counted, so a test can
 * hold a work path (blitFrom, paintImage) to never reading a source that lends memory.
 */
/** A frame seen through: counts its read-backs, and with $hidden lends no ticket (the read-back path). */
final class WatchedFrame implements InstanceThatManagesAFramebuffer
{
    public int $reads = 0;

    public function __construct(private readonly InstanceThatManagesAFramebuffer $inner, private readonly bool $hidden = false) {}

    public function viewportWidth(): int { return $this->inner->viewportWidth(); }
    public function viewportHeight(): int { return $this->inner->viewportHeight(); }
    public function hostFormat(): FormatSpec { return $this->inner->hostFormat(); }
    public function memory(): ?PixelMemory { return $this->hidden ? null : $this->inner->memory(); }
    public function pointer(): int { return $this->inner->pointer(); }
    public function drewInto(?Region ...$regions): static { $this->inner->drewInto(...$regions); return $this; }
    public function getPixel(int $x, int $y): int { $this->reads++; return $this->inner->getPixel($x, $y); }
    public function setPixel(int $x, int $y, int $value): static { $this->inner->setPixel($x, $y, $value); return $this; }
    public function setPixels(array $pixels): static { $this->inner->setPixels($pixels); return $this; }
    public function setRegion(array $coordinates, int $value): static { $this->inner->setRegion($coordinates, $value); return $this; }
    public function setSegment(int $x, int $y, int $width, int $height, int $color): static { $this->inner->setSegment($x, $y, $width, $height, $color); return $this; }
    public function clear(): static { $this->inner->clear(); return $this; }
    public function fill(int $color): static { $this->inner->fill($color); return $this; }
    public function writeRgba8(string $rgba8, int $width, int $height, int $x = 0, int $y = 0): static { $this->inner->writeRgba8($rgba8, $width, $height, $x, $y); return $this; }
    public function blitTo(InstanceThatManagesAFramebuffer $target, int $offset_x = 0, int $offset_y = 0): InstanceThatManagesAFramebuffer { return $target->blitFrom($this, $offset_x, $offset_y); }
    public function blitFrom(InstanceThatManagesAFramebuffer $source, int $offset_x = 0, int $offset_y = 0): InstanceThatManagesAFramebuffer { $this->inner->blitFrom($source, $offset_x, $offset_y); return $this; }
    public function toRgba8(): string { $this->reads++; return $this->inner->toRgba8(); }
    public function dump(?int $layer = null): string { $this->reads++; return $this->inner->dump($layer); }
    public function flush(FormatSpec $spec, bool $as_array = false): string|array { $this->reads++; return $this->inner->flush($spec, $as_array); }
    public function flushRegion(Region $region, FormatSpec $spec, bool $as_array = false): string|array { $this->reads++; return $this->inner->flushRegion($region, $spec, $as_array); }
    public function scratch(int $width, int $height): InstanceThatManagesAFramebuffer { return $this->inner->scratch($width, $height); }
    public function damageGranularity(): DamageGranularity { return $this->inner->damageGranularity(); }
    public function preservesContentsOnPresent(): bool { return $this->inner->preservesContentsOnPresent(); }
}
