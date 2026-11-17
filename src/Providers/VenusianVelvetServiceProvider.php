<?php

namespace Jovian\Engines\Velvet\Providers;

use Jovian\Engines\Velvet\Lustrous\Framebuffers\CLandFramebufferDriver;
use Jovian\Engines\Velvet\Lustrous\Images\CLandImageDecoderDriver;
use Jovian\Engines\Velvet\Lustrous\Images\PHPLandImageDecoderDriver;
use Jovian\Engines\Velvet\Lustrous\Framebuffers\PHPLandFramebufferDriver;
use Jovian\Engines\Velvet\Lustrous\Framebuffers\StoreFramebufferDriver;
use Jovian\Engines\Velvet\Lustrous\Rasterization\CLandRasterizationDriver;
use Jovian\Engines\Velvet\Lustrous\Rasterization\PHPLandRasterizationDriver;
use Jovian\Engines\Velvet\Lustrous\Painting\CLandPaintbrushDriver;
use Jovian\Engines\Velvet\Lustrous\Painting\PHPLandPaintbrushDriver;
use Jovian\Engines\Velvet\Lustrous\Raycasting\CLandRaycastingDriver;
use Jovian\Engines\Velvet\Lustrous\Raycasting\PHPLandRaycastingDriver;
use Jovian\Engines\Velvet\Lustrous\Stroking\CLandStrokingDriver;
use Jovian\Engines\Velvet\Lustrous\Stroking\PHPLandStrokingDriver;
use Jovian\Engines\Velvet\Lustrous\Typography\CLandFontCatalogDriver;
use Jovian\Engines\Velvet\Lustrous\Typography\CLandFontFaceDriver;
use Jovian\Engines\Velvet\Lustrous\Typography\CLandFontShaperDriver;
use Jovian\Engines\Velvet\Lustrous\Typography\PHPLandFontCatalogDriver;
use Jovian\Engines\Velvet\Lustrous\Typography\PHPLandFontFaceDriver;
use Jovian\Engines\Velvet\Lustrous\Typography\PHPLandFontShaperDriver;
use Jovian\Engines\Velvet\Lustrous\Typography\Typog;
use Jovian\Engines\Velvet\Lustrous\Glyphs\AutoGlyphDriver;
use Jovian\Engines\Velvet\Lustrous\Glyphs\CLandGlyphDriver;
use Jovian\Engines\Velvet\Lustrous\Glyphs\CLandGlyphStore;
use Jovian\Engines\Velvet\Lustrous\Glyphs\PHPLandGlyphDriver;
use Voyager\NutsAndBolts\ServiceProvider;

class VenusianVelvetServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->registerVelvetDrivers();
    }

    protected function registerVelvetDrivers(): void
    {
        $this->registerFramebufferDrivers();
        $this->registerRasterizationDrivers();
        $this->registerPaintbrushDrivers();
        $this->registerStrokingDrivers();
        $this->registerRaycastingDrivers();
        $this->registerTypographyDrivers();
        $this->registerGlyphDrivers();
        $this->registerImageDecoders();
    }

    /**
     * Registers Lustrous's framebuffers on Velvet: 'lustrous-php' and 'lustrous-c' make a full frame,
     * 'lustrous-php-<kind>' and 'lustrous-c-<kind>' make that kind. The C ones only where ext-fb (or
     * ext-velvet) is loaded.
     */
    protected function registerFramebufferDrivers(): void
    {
        $framebuffers = app('velvet.framebuffers');
        $page_rows = (int) config('velvet.cpu.framebuffers.lustrous.page_rows', 0);
        $frames = (int) config('velvet.cpu.framebuffers.lustrous.frames', 2);
        $c = extension_loaded('fb') || extension_loaded('velvet');

        $framebuffers->extend('lustrous-php', fn () => new PHPLandFramebufferDriver(null, 'full', $page_rows, $frames));
        if ($c) {
            $framebuffers->extend('lustrous-c', fn () => new CLandFramebufferDriver(null, 'full', $page_rows, $frames));
        }

        foreach (StoreFramebufferDriver::KINDS as $kind) {
            $framebuffers->extend("lustrous-php-{$kind}", fn () => new PHPLandFramebufferDriver($kind, 'full', $page_rows, $frames));
            if ($c) {
                $framebuffers->extend("lustrous-c-{$kind}", fn () => new CLandFramebufferDriver($kind, 'full', $page_rows, $frames));
            }
        }
    }

    /** Registers Lustrous's rasterizers on Velvet's rasterization manager. */
    protected function registerRasterizationDrivers(): void
    {
        $rasterizers = app('velvet.rasterizers');
        foreach (static::rasterizationDrivers() as $name => $make) {
            $rasterizers->extend($name, $make);
        }
    }

    /**
     * Lustrous's rasterizers by name: 'lustrous-php' everywhere, 'lustrous-c' only where
     * ext-rasterize (or ext-velvet) is loaded, and 'auto', which takes 'lustrous-c' when it can run
     * and 'lustrous-php' otherwise. 'auto' chooses between those two and nothing else.
     *
     * @return array<string, \Closure(): \VelvetGE\Rasterization\ScannerRasterizationDriver>
     */
    public static function rasterizationDrivers(): array
    {
        $c = extension_loaded('rasterize') || extension_loaded('velvet');
        $drivers = ['lustrous-php' => fn () => new PHPLandRasterizationDriver()];
        if ($c) {
            $drivers['lustrous-c'] = fn () => new CLandRasterizationDriver();
        }
        $drivers['auto'] = $c ? $drivers['lustrous-c'] : $drivers['lustrous-php'];

        return $drivers;
    }

    /** Registers Lustrous's painters on Velvet's painting manager. */
    protected function registerPaintbrushDrivers(): void
    {
        $painters = app('velvet.painters');
        foreach (static::paintbrushDrivers() as $name => $make) {
            $painters->extend($name, $make);
        }
    }

    /**
     * Lustrous's painters by name: 'lustrous-php' everywhere, 'lustrous-c' only where ext-paint
     * (or ext-velvet) is loaded, and 'auto', which takes 'lustrous-c' when it can run and
     * 'lustrous-php' otherwise. 'auto' chooses between those two and nothing else.
     *
     * @return array<string, \Closure(): \VelvetGE\Painting\BrushPaintingDriver>
     */
    public static function paintbrushDrivers(): array
    {
        $c = extension_loaded('paint') || extension_loaded('velvet');
        $drivers = ['lustrous-php' => fn () => new PHPLandPaintbrushDriver()];
        if ($c) {
            $drivers['lustrous-c'] = fn () => new CLandPaintbrushDriver();
        }
        $drivers['auto'] = $c ? $drivers['lustrous-c'] : $drivers['lustrous-php'];

        return $drivers;
    }

    /** Registers Lustrous's strokers on Topside's stroking manager: stroking is Rendering's, for every engine. */
    protected function registerStrokingDrivers(): void
    {
        $strokers = app('strokers');
        foreach (static::strokingDrivers() as $name => $make) {
            $strokers->extend($name, $make);
        }
    }

    /**
     * Lustrous's strokers by name: 'lustrous-php' everywhere, 'lustrous-c' only where ext-rasterize
     * (or ext-velvet) has RasterStroker, and 'auto', which takes 'lustrous-c' when it can run and
     * 'lustrous-php' otherwise. 'auto' chooses between those two and nothing else.
     *
     * @return array<string, \Closure(): \Surface\Rendering\Stroking\StrokingDriver>
     */
    public static function strokingDrivers(): array
    {
        $c = class_exists(\RasterStroker::class, false);
        $drivers = ['lustrous-php' => fn () => new PHPLandStrokingDriver()];
        if ($c) {
            $drivers['lustrous-c'] = fn () => new CLandStrokingDriver();
        }
        $drivers['auto'] = $c ? $drivers['lustrous-c'] : $drivers['lustrous-php'];

        return $drivers;
    }

    /** Registers Lustrous's raycasters on Velvet's raycasting manager. */
    protected function registerRaycastingDrivers(): void
    {
        $raycasters = app('velvet.raycasters');
        foreach (static::raycastingDrivers() as $name => $make) {
            $raycasters->extend($name, $make);
        }
    }

    /**
     * Lustrous's raycasters by name: 'lustrous-php' everywhere, 'lustrous-c' only where ext-raycast
     * (or ext-velvet) is new enough, and 'auto', which takes 'lustrous-c' when it can run and
     * 'lustrous-php' otherwise. 'auto' chooses between those two and nothing else.
     *
     * @return array<string, \Closure(): \VelvetGE\Raycasting\MarcherRaycastingDriver>
     */
    public static function raycastingDrivers(): array
    {
        $c = CLandRaycastingDriver::available();
        $drivers = ['lustrous-php' => fn () => new PHPLandRaycastingDriver()];
        if ($c) {
            $drivers['lustrous-c'] = fn () => new CLandRaycastingDriver();
        }
        $drivers['auto'] = $c ? $drivers['lustrous-c'] : $drivers['lustrous-php'];

        return $drivers;
    }

    /** Registers Lustrous's glyphs on Velvet's glyph manager. */
    protected function registerGlyphDrivers(): void
    {
        $glyphs = app('velvet.glyphs');
        foreach (static::glyphDrivers() as $name => $make) {
            $glyphs->extend($name, $make);
        }
    }

    /**
     * Lustrous's glyphs by name: 'lustrous-php' everywhere, 'lustrous-c' only where ext-rasterize
     * has RasterSpanStore and ext-fb is loaded for it to paint into, and 'auto', which keeps glyphs
     * in C for a frame in C memory where it can, and in PHP for every other frame. Each reads its
     * faces through Typography's default face driver.
     *
     * @return array<string, \Closure(): \Surface\Rendering\Glyphs\GlyphDrawingDriver>
     */
    public static function glyphDrivers(): array
    {
        $drivers = ['lustrous-php' => fn () => new PHPLandGlyphDriver()];
        if (CLandGlyphStore::available()) {
            $drivers['lustrous-c'] = fn () => new CLandGlyphDriver();
        }
        $drivers['auto'] = fn () => new AutoGlyphDriver();

        return $drivers;
    }

    /**
     * Registers Lustrous's font catalogs, faces and shapers on Typography's managers: typography is
     * Topside's, for every engine.
     */
    protected function registerTypographyDrivers(): void
    {
        foreach (static::typographyDrivers() as $manager => $drivers) {
            foreach ($drivers as $name => $make) {
                app($manager)->extend($name, $make);
            }
        }
    }

    /** Registers Lustrous's image decoders on Images' manager. */
    protected function registerImageDecoders(): void
    {
        $decoders = app('images.decoders');
        foreach (static::imageDecoders() as $name => $make) {
            $decoders->extend($name, $make);
        }
    }

    /**
     * lustrous-c, where ext-imgdec writes a frame at an address, then lustrous-php. Images' 'auto'
     * tries them in that order: the address decoder for a frame that lends memory, the PHP decoder
     * for a frame whose pixels live in PHP.
     *
     * @return array<string, \Closure(): CLandImageDecoderDriver|PHPLandImageDecoderDriver>
     */
    public static function imageDecoders(): array
    {
        $drivers = [];
        if (function_exists('imgdec_decode_into')) {
            $drivers['lustrous-c'] = fn () => new CLandImageDecoderDriver();
        }
        $drivers['lustrous-php'] = fn () => new PHPLandImageDecoderDriver();

        return $drivers;
    }

    /**
     * Lustrous's typography drivers by manager and name: 'lustrous-php' everywhere, 'lustrous-c'
     * only where ext-typog is loaded, and 'auto', which takes 'lustrous-c' when it can run and
     * 'lustrous-php' otherwise. The catalogs read their directories, files, generic families,
     * fallbacks and cache file from config/typography.php; with no cache file named, they keep their
     * scan (the same for both) in storage/framework/cache/fonts.json.
     *
     * @return array<string, array<string, \Closure(): object>>
     */
    public static function typographyDrivers(): array
    {
        $catalog = fn (string $class) => fn () => new $class(
            config('typography.directories', []),
            config('typography.files', []),
            config('typography.families', []),
            config('typography.fallbacks', []),
            config('typography.cache') ?? app()->storagePath('framework/cache/fonts.json'),
        );
        $drivers = [
            'typography.catalogs' => ['lustrous-php' => $catalog(PHPLandFontCatalogDriver::class)],
            'typography.faces' => ['lustrous-php' => fn () => new PHPLandFontFaceDriver()],
            'typography.shapers' => ['lustrous-php' => fn () => new PHPLandFontShaperDriver()],
        ];
        $c = Typog::available();
        if ($c) {
            $drivers['typography.catalogs']['lustrous-c'] = $catalog(CLandFontCatalogDriver::class);
            $drivers['typography.faces']['lustrous-c'] = fn () => new CLandFontFaceDriver();
            $drivers['typography.shapers']['lustrous-c'] = fn () => new CLandFontShaperDriver();
        }
        foreach ($drivers as $manager => $named) {
            $drivers[$manager]['auto'] = $named[$c ? 'lustrous-c' : 'lustrous-php'];
        }

        return $drivers;
    }
}
