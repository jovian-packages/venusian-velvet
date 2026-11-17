<?php

declare(strict_types=1);

use Jovian\Engines\Velvet\Lustrous\Framebuffers\CLandFramebufferDriver;
use Jovian\Engines\Velvet\Lustrous\Framebuffers\PHPLandFramebufferDriver;
use Jovian\Engines\Velvet\Lustrous\Painting\CLandPaintbrushDriver;
use Surface\Contracts\Graphics\ActorStage;
use Surface\Contracts\Graphics\Backend;
use Surface\Contracts\Graphics\Feature;
use Surface\Contracts\Graphics\GraphicsException;
use Surface\Contracts\Graphics\HostFormatStage;
use Surface\Contracts\Graphics\PacedStage;
use Surface\Contracts\Rendering\Framebuffers\FormatSpec;
use Surface\Contracts\Rendering\Framebuffers\FramebufferException;
use Surface\Contracts\Rendering\Framebuffers\InstanceThatManagesAFramebuffer;
use Surface\Contracts\Rendering\Painting\PaintTarget;
use Surface\Contracts\Rendering\Raycasting\Camera;
use Surface\Contracts\Rendering\Raycasting\Grid;
use Surface\Contracts\Rendering\Raycasting\Scenery;
use Surface\NutsAndBolts\Color;
use Surface\Rendering\Framebuffers\FramebufferDriver;
use VelvetGE\Contracts\Framebuffers\CompositingFramebuffer;
use VelvetGE\VelvetDevice;
use VelvetGE\VelvetGEException;

require_once __DIR__.'/../Support/VelvetApp.php';

/*
 * Velvet as a graphics engine (Gfx::engine('velvet')): it takes its modules from Velvet's managers
 * when it is made, hands out surfaces that render, read back and present on a stage, and casts rays
 * through its raycasting module. Run over Lustrous's modules, in PHP and in C where the C ones load.
 */

/** Velvet over the modules $config names, in a fresh container. */
function velvet(array $config = [], array $modules = []): VelvetDevice
{
    velvetApp($config, $modules);

    return new VelvetDevice(Backend::CPU);
}

/** Config naming one framebuffer module and one painting module. */
function velvetModules(string $framebuffer, string $painter): array
{
    return ['velvet.cpu.framebuffers.default' => $framebuffer, 'velvet.cpu.painters.default' => $painter];
}

/** A stage of a given size, which keeps what it was shown. */
function sizedStage(int $width, int $height): ActorStage
{
    return new class($width, $height) implements ActorStage {
        /** @var list<array{string, int, int}> */
        public array $shown = [];

        public function __construct(private readonly int $width, private readonly int $height) {}

        public function pixelSize(): array { return [$this->width, $this->height]; }

        public function pixelScale(): float { return 1.0; }

        public function display(string $rgba8, int $width, int $height): void { $this->shown[] = [$rgba8, $width, $height]; }

        public function nativeSurface(): ?object { return null; }
    };
}

/** A framebuffer module that is Lustrous's in PHP, but refuses the formats named. */
function refusingFormats(string ...$refused): FramebufferDriver
{
    return new class($refused) extends PHPLandFramebufferDriver {
        public function __construct(private readonly array $refused)
        {
            parent::__construct();
        }

        public function make(FormatSpec $format, int $width, int $height): InstanceThatManagesAFramebuffer
        {
            foreach ($this->refused as $name) {
                if ($format->equals(FormatSpec::named($name))) {
                    throw new FramebufferException("{$name} refused");
                }
            }

            return parent::make($format, $width, $height);
        }
    };
}

/** A framebuffer module whose frames are the object $make gives. */
function framesOf(Closure $make): FramebufferDriver
{
    return new class($make) extends FramebufferDriver {
        public function __construct(private readonly Closure $make) {}

        public function driver(): string { return 'test'; }

        public function make(FormatSpec $format, int $width, int $height): InstanceThatManagesAFramebuffer
        {
            return ($this->make)();
        }
    };
}

/** A frame Velvet can paint into that has nothing beyond that: no gradients, effects or scrolling. */
interface PlainVelvetFrame extends CompositingFramebuffer, PaintTarget {}

function velvetRoom(): Grid
{
    return new Grid(5, 5, "\x01\x01\x01\x01\x01"."\x01\x00\x00\x00\x02"."\x01\x00\x00\x00\x02"."\x01\x00\x00\x00\x02"."\x01\x03\x03\x03\x01");
}

dataset('velvet modules', function (): array {
    $sets = ['lustrous-php' => ['lustrous-php-full', 'lustrous-php']];
    if (CLandFramebufferDriver::available() && extension_loaded('paint')) {
        $sets['lustrous-c'] = ['lustrous-c-full', 'lustrous-c'];
    }

    return $sets;
});

it('is the velvet engine on the CPU, naming the modules config/velvet.php names for it', function () {
    // a name for each part, none the same, so a part reported under another's name shows
    $engine = velvet(velvetModules('lustrous-php-ring', 'auto') + ['velvet.cpu.rasterizers.default' => 'some-rasterizer', 'velvet.cpu.advanced.raycasting.default' => 'lustrous-php']);

    expect($engine->name())->toBe('velvet')
        ->and($engine->backend())->toBe(Backend::CPU)
        ->and($engine->modules())->toBe(['framebuffer' => 'lustrous-php-ring', 'rasterizer' => 'some-rasterizer', 'painter' => 'auto', 'raycaster' => 'lustrous-php']);
});

it('has gradients, effects and scrolling when its framebuffer module\'s frames do, and not when they do not', function () {
    $full = velvet(velvetModules('lustrous-php-full', 'lustrous-php'));
    $plain = $this->createStub(PlainVelvetFrame::class);
    $bare = velvet(velvetModules('bare', 'lustrous-php'), ['velvet.framebuffers' => ['bare' => fn () => framesOf(fn () => $plain)]]);

    expect([$full->has(Feature::GRADIENTS), $full->has(Feature::EFFECTS), $full->has(Feature::SCROLLING)])->toBe([true, true, true])
        ->and([$bare->has(Feature::GRADIENTS), $bare->has(Feature::EFFECTS), $bare->has(Feature::SCROLLING)])->toBe([false, false, false]);
});

it('casts rays only when the raycasting module config/velvet.php names is installed', function () {
    $with = velvet(['velvet.cpu.advanced.raycasting.default' => 'lustrous-php']);
    $without = velvet(['velvet.cpu.advanced.raycasting.default' => 'not-installed']);

    expect($with->has(Feature::RAY_CASTING))->toBeTrue()
        ->and($without->has(Feature::RAY_CASTING))->toBeFalse()
        ->and(fn () => $without->raycaster(velvetRoom()))->toThrow(InvalidArgumentException::class, 'not-installed');
});

it('refuses, when it is made, a framebuffer module whose frames cannot be painted into, naming the module', function () {
    $unpaintable = $this->createStub(InstanceThatManagesAFramebuffer::class);

    expect(fn () => velvet(velvetModules('pixels-only', 'lustrous-php'), ['velvet.framebuffers' => ['pixels-only' => fn () => framesOf(fn () => $unpaintable)]]))
        ->toThrow(VelvetGEException::class, "Velvet's framebuffer module [pixels-only] holds pixels but cannot be painted into");
});

it('makes surfaces in RGBA8, in BGRA8 when its framebuffer module refuses RGBA8, and refuses a module that holds neither', function () {
    $bgra = velvet(velvetModules('no-rgba8', 'lustrous-php'), ['velvet.framebuffers' => ['no-rgba8' => fn () => refusingFormats('rgba8')]]);

    expect(velvet()->offscreen(2, 2)->format())->toEqual(FormatSpec::rgba8())
        ->and($bgra->offscreen(2, 2)->format())->toEqual(FormatSpec::bgra8())
        ->and(fn () => velvet(velvetModules('neither', 'lustrous-php'), ['velvet.framebuffers' => ['neither' => fn () => refusingFormats('rgba8', 'bgra8')]]))
        ->toThrow(VelvetGEException::class, "Velvet's framebuffer module [neither] holds none of the host formats: rgba8: rgba8 refused bgra8: bgra8 refused");
});

it('makes an offscreen surface of the size and format asked, and refuses a side under 1', function () {
    $engine = velvet();
    $surface = $engine->offscreen(20, 10, FormatSpec::bgra8());

    expect([$surface->width(), $surface->height()])->toBe([20, 10])
        ->and($surface->format())->toEqual(FormatSpec::bgra8())
        ->and($surface->engine())->toBe($engine)
        ->and(fn () => $engine->offscreen(0, 10))->toThrow(GraphicsException::class, 'A surface of 0x10 cannot be made: both sides are 1 or more.');
});

it('makes a stage\'s surface the stage\'s pixel size, and refuses a stage not laid out yet', function () {
    $surface = velvet()->surface(sizedStage(7, 3));

    expect([$surface->width(), $surface->height()])->toBe([7, 3])
        ->and(fn () => velvet()->surface(sizedStage(0, 3)))->toThrow(GraphicsException::class, 'The stage has no size yet');
});

it('renders a frame with its painting module and reads it back as RGBA8', function (string $framebuffer, string $painter) {
    $surface = velvet(velvetModules($framebuffer, $painter))->offscreen(4, 3, FormatSpec::bgra8())
        ->render(fn ($g) => $g->clear(Color::rgb(0, 0, 255))->fillRect(1, 1, 2, 1, Color::rgb(255, 0, 0)));

    $blue = '0000ffff';
    $red = 'ff0000ff';
    expect(bin2hex($surface->read()))->toBe(str_repeat($blue, 4).$blue.$red.$red.$blue.str_repeat($blue, 4));
})->with('velvet modules');

it('refuses to present a surface that has no stage', function () {
    expect(fn () => velvet()->offscreen(2, 2)->present())->toThrow(GraphicsException::class, 'This surface has no stage to present on');
});

it('shows a stage the frame as RGBA8 at each present()', function (string $framebuffer, string $painter) {
    $stage = sizedStage(2, 1);
    $surface = velvet(velvetModules($framebuffer, $painter))->surface($stage, FormatSpec::bgra8());

    $surface->render(fn ($g) => $g->clear(Color::rgb(255, 0, 0)))->present();
    $surface->render(fn ($g) => $g->clear(Color::rgb(0, 255, 0)))->present();

    expect($stage->shown)->toBe([[hex2bin(str_repeat('ff0000ff', 2)), 2, 1], [hex2bin(str_repeat('00ff00ff', 2)), 2, 1]])
        ->and($surface->shown())->toBe(2);
})->with('velvet modules');

it('hands a stage that shows the surface\'s own format its pixels in that format, as they are', function () {
    $stage = new class implements HostFormatStage {
        public array $own = [];

        public array $rgba8 = [];

        public function __construct(public array $formats = []) {}

        public function pixelSize(): array { return [1, 1]; }

        public function pixelScale(): float { return 1.0; }

        public function display(string $rgba8, int $width, int $height): void { $this->rgba8[] = $rgba8; }

        public function nativeSurface(): ?object { return null; }

        public function hostFormats(): array { return $this->formats; }

        public function displayPixels(string $pixels, int $width, int $height, FormatSpec $format): void { $this->own[] = [$pixels, $format]; }
    };
    $red = fn ($g) => $g->clear(Color::rgb(255, 0, 0));

    $stage->formats = [FormatSpec::bgra8()];
    velvet()->surface($stage, FormatSpec::bgra8())->render($red)->present();
    $stage->formats = [FormatSpec::rgba8()];
    velvet()->surface($stage, FormatSpec::bgra8())->render($red)->present();

    expect($stage->own)->toEqual([[hex2bin('0000ffff'), FormatSpec::bgra8()]])
        ->and($stage->rgba8)->toBe([hex2bin('ff0000ff')]);
});

it('shows a paced stage nothing until its display is due a frame, then the latest frame, once', function () {
    $stage = new class implements PacedStage {
        public array $shown = [];

        public array $due = [];

        public function pixelSize(): array { return [1, 1]; }

        public function pixelScale(): float { return 1.0; }

        public function display(string $rgba8, int $width, int $height): void { $this->shown[] = bin2hex($rgba8); }

        public function nativeSurface(): ?object { return null; }

        public function whenDue(Closure $show): void { $this->due[] = $show; }
    };
    $surface = velvet()->surface($stage);

    $surface->render(fn ($g) => $g->clear(Color::rgb(255, 0, 0)))->present();
    $surface->render(fn ($g) => $g->clear(Color::rgb(0, 255, 0)))->present();
    expect($stage->shown)->toBe([])
        ->and($stage->due)->toHaveCount(1);

    ($stage->due[0])();
    expect($stage->shown)->toBe(['00ff00ff'])
        ->and($surface->shown())->toBe(1);

    $surface->present();
    expect($stage->due)->toHaveCount(2);
});

it('paints a raycaster\'s view onto one of its surfaces, and answers its casts', function () {
    $engine = velvet(['velvet.cpu.advanced.raycasting.default' => 'lustrous-php']);
    $surface = $engine->offscreen(8, 8)->render(fn ($g) => $g->clear(Color::rgb(255, 255, 255)));
    $caster = $engine->raycaster(velvetRoom());

    // facing east from the room's middle: the wall of 2s is 1.5 cells away
    $hit = $caster->cast(2.5, 2.5, 0.0);
    $caster->paint(new Camera(2.5, 2.5, 0.0, 1.1), new Scenery([1 => 0xC04020FF, 2 => 0x2040C0FF, 3 => 0x20C040FF], 0x111111FF, 0x222222FF, 160), $surface);
    $middle = substr($surface->read(), (4 * 8 + 4) * 4, 4);

    expect($caster->grid())->toBe($caster->grid())
        ->and([$hit->x, $hit->y, $hit->wall])->toBe([4, 2, 2])
        ->and($hit->distance)->toEqualWithDelta(1.5, 1e-9)
        ->and(bin2hex($middle))->toBe('2040c0ff');
});
