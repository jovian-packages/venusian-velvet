<?php

declare(strict_types=1);

use Surface\Contracts\Graphics\Backend;
use Surface\Contracts\Graphics\PacedStage;
use Surface\Contracts\HumanInput\InputChannel;
use Surface\Contracts\HumanInput\InputStage;
use VelvetGE\VelvetDevice;

require_once __DIR__.'/../Support/VelvetApp.php';

/*
 * A stage that takes input has it settled each time a frame is presented on it, so input read
 * between frames belongs to the frame drawn next.
 */

function inputVelvet(): VelvetDevice
{
    velvetApp();

    return new VelvetDevice(Backend::CPU);
}

it('settles a stage\'s input each time a frame is presented on it', function () {
    $stage = inputStage();
    $surface = inputVelvet()->surface($stage);

    $surface->present();
    $surface->present();

    expect($stage->settles)->toBe(2)
        ->and($stage->displays)->toBe(2);
});

it('settles as a frame is presented on a paced stage, not when the refresh shows it', function () {
    $stage = new class implements InputStage, PacedStage {
        public int $settles = 0;

        public ?Closure $due = null;

        public function pixelSize(): array { return [4, 4]; }

        public function pixelScale(): float { return 1.0; }

        public function display(string $rgba8, int $width, int $height): void {}

        public function nativeSurface(): ?object { return null; }

        public function whenDue(Closure $show): void { $this->due = $show; }

        public function input(): InputChannel { throw new LogicException('not read'); }

        public function settleInput(): void { $this->settles++; }
    };
    $surface = inputVelvet()->surface($stage);

    $surface->present();
    expect($stage->settles)->toBe(1);

    // input that comes before the refresh is the next frame's
    ($stage->due)();
    expect($stage->settles)->toBe(1);
});
