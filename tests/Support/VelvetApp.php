<?php

declare(strict_types=1);

use Jovian\Engines\Velvet\Providers\VenusianVelvetServiceProvider;
use Surface\Rendering\Recording\Drivers\CLandRecordingDriver;
use Surface\Rendering\Recording\Drivers\PHPLandRecordingDriver;
use Surface\Rendering\Recording\RecordingLibraryManager;
use Surface\Rendering\Stroking\StrokingLibraryManager;
use Surface\Typography\Catalogs\FontCatalogLibraryManager;
use Surface\Typography\Faces\FontFaceLibraryManager;
use Surface\Typography\Shapers\FontShaperLibraryManager;
use Surface\Images\ImageDecoderManager;
use VelvetGE\Framebuffers\VelvetFramebufferManager;
use VelvetGE\Glyphs\VelvetGlyphManager;
use VelvetGE\Painting\VelvetPaintbrushManager;
use VelvetGE\Rasterization\VelvetRasterizationManager;
use VelvetGE\Raycasting\VelvetRaycastingManager;
use Voyager\Vessel\ControlPanel;

/*
 * Velvet as an application has it: a container holding config and Velvet's managers, with Lustrous's
 * service provider booted onto them. VelvetDevice reaches its modules through app() and config(),
 * which venusian/framework defines; this suite does not load the framework, so they are defined here
 * the way the framework defines them, over a real ControlPanel.
 */

if (! function_exists('app')) {
    function app(?string $abstract = null, array $parameters = []): mixed
    {
        return is_null($abstract) ? ControlPanel::getInstance() : ControlPanel::getInstance()->make($abstract, $parameters);
    }
}

if (! function_exists('config')) {
    function config(?string $key = null, mixed $default = null): mixed
    {
        return is_null($key) ? app('config') : app('config')->get($key, $default);
    }
}

/**
 * A fresh container with $config, Velvet's and Topside's managers, and Lustrous's drivers registered
 * on them by its provider's boot(). Each call replaces the container the last one made.
 *
 * @param  array<string, mixed>  $config  config key => value, e.g. 'velvet.cpu.framebuffers.default'
 * @param  array<string, array<string, Closure(): object>>  $modules  manager => name => creator: a module's drivers, registered after Lustrous's
 */
function velvetApp(array $config = [], array $modules = []): ControlPanel
{
    $app = new ControlPanel();
    ControlPanel::setInstance($app);
    $app->registerInstance('config', fakeConfig($config));

    foreach ([
        'velvet.framebuffers' => VelvetFramebufferManager::class,
        'velvet.rasterizers' => VelvetRasterizationManager::class,
        'velvet.painters' => VelvetPaintbrushManager::class,
        'velvet.raycasters' => VelvetRaycastingManager::class,
        'velvet.glyphs' => VelvetGlyphManager::class,
        'strokers' => StrokingLibraryManager::class,
        'typography.catalogs' => FontCatalogLibraryManager::class,
        'typography.faces' => FontFaceLibraryManager::class,
        'typography.shapers' => FontShaperLibraryManager::class,
        'recorders' => RecordingLibraryManager::class,
        'images.decoders' => ImageDecoderManager::class,
    ] as $name => $class) {
        $app->registerInstance($name, new $class($app));
    }
    // Recording is Topside's: RenderingServiceProvider registers its two drivers.
    $app->make('recorders')->extend('php', fn () => new PHPLandRecordingDriver())->extend('c', fn () => new CLandRecordingDriver());

    // The provider's constructor wants the framework's core; boot() only reaches the managers through app().
    (new ReflectionClass(VenusianVelvetServiceProvider::class))->newInstanceWithoutConstructor()->boot();

    foreach ($modules as $manager => $drivers) {
        foreach ($drivers as $name => $make) {
            $app->make($manager)->extend($name, $make);
        }
    }

    return $app;
}
