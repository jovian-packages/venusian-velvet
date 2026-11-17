<?php

declare(strict_types=1);

use Jovian\Engines\Velvet\Lustrous\Framebuffers\CLandFramebufferDriver;
use Jovian\Engines\Velvet\Lustrous\Framebuffers\PHPLandFramebufferDriver;
use Jovian\Engines\Velvet\Lustrous\Rasterization\CLandRasterizationDriver;
use Jovian\Engines\Velvet\Lustrous\Rasterization\PHPLandRasterizationDriver;
use Surface\Rendering\Framebuffers\FramebufferLibraryManager;
use Surface\Rendering\Rasterization\RasterizationLibraryManager;

// Managers over Lustrous's drivers, built the way the topside fakeConfig() ones are.

/**
 * A framebuffers manager reading the given config, without a container, with VelvetGE's two
 * drivers registered as VelvetGEServiceProvider registers them.
 */
function framebuffers(array $config = []): FramebufferLibraryManager
{
    $manager = new class($config) extends FramebufferLibraryManager {
        public function __construct(array $config)
        {
            $this->config = fakeConfig($config);
        }

        /** No container here: a creator is called with nothing. */
        protected function callCustomCreator(string $driver): mixed
        {
            return $this->customCreators[$driver]();
        }
    };

    return $manager
        ->extend('native', fn () => new PHPLandFramebufferDriver())
        ->extend('ext', fn () => new CLandFramebufferDriver());
}

/**
 * A rasterization manager reading the given config, without a container, with VelvetGE's two
 * drivers registered as VelvetGEServiceProvider registers them.
 */
function rasterize(array $config = []): RasterizationLibraryManager
{
    $manager = new class($config) extends RasterizationLibraryManager {
        public function __construct(array $config)
        {
            $this->config = fakeConfig($config);
        }

        /** No container here: a creator is called with nothing. */
        protected function callCustomCreator(string $driver): mixed
        {
            return $this->customCreators[$driver]();
        }
    };

    return $manager
        ->extend('native', fn () => new PHPLandRasterizationDriver())
        ->extend('ext', fn () => new CLandRasterizationDriver());
}

/**
 * A painting manager reading the given config, without a container, with VelvetGE's two drivers
 * registered as VelvetGEServiceProvider registers them, over the native rasterization driver.
 */
function painters(array $config = []): \Surface\Rendering\Painters\PaintingLibraryManager
{
    $manager = new class($config) extends \Surface\Rendering\Painters\PaintingLibraryManager {
        public function __construct(array $config)
        {
            $this->config = fakeConfig($config);
        }

        /** No container here: a creator is called with nothing. */
        protected function callCustomCreator(string $driver): mixed
        {
            return $this->customCreators[$driver]();
        }
    };

    return $manager
        ->extend('native', fn () => new \Jovian\Engines\Velvet\Lustrous\Painting\PHPLandPaintbrushDriver(new PHPLandRasterizationDriver(), true, new \Surface\Rendering\Recording\Drivers\PHPLandRecordingDriver()))
        ->extend('ext', fn () => new \Jovian\Engines\Velvet\Lustrous\Painting\CLandPaintbrushDriver(new PHPLandRasterizationDriver(), true, new \Surface\Rendering\Recording\Drivers\PHPLandRecordingDriver()));
}
