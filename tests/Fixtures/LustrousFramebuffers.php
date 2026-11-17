<?php

declare(strict_types=1);

namespace Jovian\Engines\Velvet\Tests\Fixtures;

use Jovian\Engines\Velvet\Lustrous\Framebuffers\DirtyFramebuffer;
use Jovian\Engines\Velvet\Lustrous\Framebuffers\ePaperFramebuffer;
use Jovian\Engines\Velvet\Lustrous\Framebuffers\FullFramebuffer;
use Jovian\Engines\Velvet\Lustrous\Framebuffers\PagedFramebuffer;
use Jovian\Engines\Velvet\Lustrous\Framebuffers\RingFramebuffer;
use Jovian\Engines\Velvet\Lustrous\Framebuffers\StoreFramebufferDriver;
use Surface\Contracts\Rendering\Framebuffers\FormatSpec;
use VelvetGE\Contracts\Framebuffers\PixelStore;
use Venusian\Topside\Tests\Fixtures\FakeMinter;

/** Lustrous's kinds over the fake store, and a driver over a store that leaves tiers out. */
final class FakeFullFramebuffer extends FullFramebuffer
{
    public function __construct(FormatSpec $format, int $width, int $height)
    {
        parent::__construct(new FakeMinter(), $format, $width, $height);
    }
}

final class FakeDirtyFramebuffer extends DirtyFramebuffer
{
    public function __construct(FormatSpec $format, int $width, int $height)
    {
        parent::__construct(new FakeMinter(), $format, $width, $height);
    }
}

final class FakeePaperFramebuffer extends ePaperFramebuffer
{
    public function __construct(FormatSpec $format, int $width, int $height)
    {
        parent::__construct(new FakeMinter(), $format, $width, $height);
    }
}

final class FakePagedFramebuffer extends PagedFramebuffer
{
    public function __construct(FormatSpec $format, int $width, int $height, int $page_rows)
    {
        parent::__construct(new FakeMinter(), $format, $width, $height, $page_rows);
    }
}

final class FakeRingFramebuffer extends RingFramebuffer
{
    public function __construct(FormatSpec $format, int $width, int $height, int $frames)
    {
        parent::__construct(new FakeMinter(), $format, $width, $height, $frames);
    }
}

/** A driver over a store that leaves tiers out, as a library binding registers one. */
final class PartialFramebufferDriver extends StoreFramebufferDriver
{
    /** @param class-string<PixelStore> $store */
    public function __construct(private readonly string $name, private readonly string $store) {}

    protected function module(): string
    {
        return $this->name;
    }

    public function driver(): string
    {
        return $this->name;
    }

    public function mint(FormatSpec $format, int $width, int $height): PixelStore
    {
        return new ($this->store)($format, $width, $height);
    }
}
