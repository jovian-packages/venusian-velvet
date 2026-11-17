<?php

declare(strict_types=1);

use Jovian\Engines\Velvet\Lustrous\Framebuffers\CLandFramebufferDriver;
use Jovian\Engines\Velvet\Lustrous\Framebuffers\DirtyFramebuffer;
use Jovian\Engines\Velvet\Lustrous\Framebuffers\PHPLandFramebufferDriver;
use Jovian\Engines\Velvet\Lustrous\Framebuffers\StoreFramebuffer;
use Jovian\Engines\Velvet\Lustrous\Rasterization\PHPLandRasterizationDriver;
use Surface\Contracts\Rendering\Framebuffers\BitDepth;
use Surface\Contracts\Rendering\Framebuffers\ChannelOrder;
use Surface\Contracts\Rendering\Framebuffers\FormatSpec;
use Surface\Contracts\Rendering\Framebuffers\FramebufferException;
use Surface\Contracts\Rendering\Framebuffers\PixelFormat;
use Surface\Contracts\Rendering\Framebuffers\PixelMemory;
use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\Contracts\Rendering\Framebuffers\ScanDirection;
use Surface\Contracts\Rendering\Painting\Painter;
use VelvetGE\Painting\VelvetPainter;

/*
 * A decoded image is a frame whose pixels were written by the store's own path, then drawn with
 * the image command. The C store's memory() is the ticket the paint reads; drewInto() is the
 * report that those bytes were written. The PHP store has no ticket, and drewInto() says so.
 */

function imagePainter(StoreFramebuffer $frame): VelvetPainter
{
    return new VelvetPainter($frame, new PHPLandRasterizationDriver());
}

/** RGBA8, top to bottom or bottom to top. Row 0 of the bytes is still the top row; scan is how a region is emitted. */
function imageFormat(ScanDirection $scan): FormatSpec
{
    return new FormatSpec(PixelFormat::ROW_MAJOR, BitDepth::B32, $scan, channel_order: ChannelOrder::RGBA);
}

/**
 * A C frame whose memory() is $ticket, not the packed buffer it was minted with. toRgba8() is zeros,
 * so a paint that ignores the ticket and reads the string paints nothing.
 */
final class TicketFrame extends DirtyFramebuffer
{
    public function __construct(private readonly PixelMemory $ticket, int $width, int $height)
    {
        parent::__construct(new CLandFramebufferDriver(), FormatSpec::rgba8(), $width, $height);
    }

    public function memory(): ?PixelMemory
    {
        return $this->ticket;
    }

    public function toRgba8(): string
    {
        return str_repeat("\0", $this->viewportWidth() * $this->viewportHeight() * 4);
    }
}

it('paints a PHP store\'s own pixels with the image command, at the place asked', function (): void {
    $frames = new PHPLandFramebufferDriver();
    $source = $frames->dirty(FormatSpec::rgba8(), 2, 2);
    // Row 0 is red, green. Row 1 is blue, white. The string is the store's own write, not a C call.
    $source->writeRgba8("\xff\x00\x00\xff"."\x00\xff\x00\xff"."\x00\x00\xff\xff"."\xff\xff\xff\xff", 2, 2);
    $dest = $frames->full(FormatSpec::rgba8(), 8, 6);

    imagePainter($dest)->frame(fn (Painter $g) => $g->image($source, 3, 2));

    expect($dest->getPixel(3, 2))->toBe(0xFF0000FF)
        ->and($dest->getPixel(4, 2))->toBe(0x00FF00FF)
        ->and($dest->getPixel(3, 3))->toBe(0x0000FFFF)
        ->and($dest->getPixel(4, 3))->toBe(0xFFFFFFFF)
        ->and($dest->getPixel(2, 2))->toBe(0)
        ->and($dest->getPixel(3, 1))->toBe(0)
        ->and(fn () => $source->drewInto(new Region(0, 0, 2, 2)))->toThrow(FramebufferException::class, 'no memory');
});

it('paints the bytes a C frame reported with drewInto, with its first stored row at the top', function (ScanDirection $scan): void {
    $frames = new CLandFramebufferDriver();
    $source = $frames->dirty(imageFormat($scan), 2, 2);
    $source->setPixel(0, 0, 0xFF0000FF);
    $source->setPixel(1, 0, 0x00FF00FF);
    $source->setPixel(0, 1, 0x0000FFFF);
    $source->setPixel(1, 1, 0xFFFFFFFF);
    $source->beginEpoch();
    $source->drewInto(new Region(0, 0, 2, 2));
    $dest = $frames->full(FormatSpec::rgba8(), 8, 6);

    imagePainter($dest)->frame(fn (Painter $g) => $g->image($source, 3, 2));

    $emitted = $source->flush($source->hostFormat());
    expect($source->written())->toEqual([new Region(0, 0, 2, 2)])
        ->and($dest->getPixel(3, 2))->toBe(0xFF0000FF)
        ->and($dest->getPixel(4, 2))->toBe(0x00FF00FF)
        ->and($dest->getPixel(3, 3))->toBe(0x0000FFFF)
        ->and($dest->getPixel(4, 3))->toBe(0xFFFFFFFF)
        ->and($dest->getPixel(2, 2))->toBe(0)
        ->and(substr($emitted, 0, 4))->toBe($scan === ScanDirection::BOTTOM_TO_TOP ? "\x00\x00\xff\xff" : "\xff\x00\x00\xff");
})->with([
    'top to bottom' => [ScanDirection::TOP_TO_BOTTOM],
    'bottom to top' => [ScanDirection::BOTTOM_TO_TOP],
]);

it('paints a ticket whose rows are wider than the pixels, and not the padding between them', function (): void {
    $width = 2;
    $height = 2;
    $stride = $width * 4 + 8;
    $owner = new FbBuffer(new FbFormat(FB_LAYOUT_INDEX8), $stride, $height);
    $owner->rect(0, 0, $stride, $height, 0xA5);
    $view = FbBuffer::over(new FbFormat(FB_LAYOUT_RGBA8888, channelOrder: FB_CHANNELS_RGBA), $width, $height, $owner->pointer(), $owner, $stride);
    $view->blitRgba8("\xff\x00\x00\xff"."\x00\xff\x00\xff"."\x00\x00\xff\xff"."\xff\xff\xff\xff", $width, $height, 0, 0);
    $source = new TicketFrame(new PixelMemory($owner->pointer(), $width, $height, $stride, FormatSpec::rgba8(), $owner), $width, $height);
    $dest = (new CLandFramebufferDriver())->full(FormatSpec::rgba8(), 6, 5);

    imagePainter($dest)->frame(fn (Painter $g) => $g->image($source, 1, 1));

    expect($dest->getPixel(1, 1))->toBe(0xFF0000FF)
        ->and($dest->getPixel(2, 1))->toBe(0x00FF00FF)
        ->and($dest->getPixel(1, 2))->toBe(0x0000FFFF)
        ->and($dest->getPixel(2, 2))->toBe(0xFFFFFFFF)
        ->and($dest->getPixel(3, 1))->toBe(0)
        ->and($dest->getPixel(1, 3))->toBe(0);
});
