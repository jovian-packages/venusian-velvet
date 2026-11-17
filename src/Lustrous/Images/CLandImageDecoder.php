<?php

namespace Jovian\Engines\Velvet\Lustrous\Images;

use Surface\Contracts\Images\ImageDecoder;
use Surface\Contracts\Images\ImageException;
use Surface\Contracts\Images\ImageFormat;
use Surface\Contracts\Images\ImageInfo;
use Surface\Contracts\Images\ImageSource;
use Surface\Contracts\Rendering\Framebuffers\BitDepth;
use Surface\Contracts\Rendering\Framebuffers\ChannelOrder;
use Surface\Contracts\Rendering\Framebuffers\FormatSpec;
use Surface\Contracts\Rendering\Framebuffers\PixelFormat;
use Surface\Contracts\Rendering\Framebuffers\PixelMemory;
use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\Contracts\Rendering\Framebuffers\ScanDirection;

/**
 * Lustrous's address decoder: ext-imgdec writes one frame into a PixelMemory. The pixels stay in
 * that memory. This class does not implement StoreImageDecoder, so a frame that lends no address
 * is not offered it.
 */
final class CLandImageDecoder implements ImageDecoder
{
    public function driver(): string
    {
        return 'lustrous-c';
    }

    public function formats(): array
    {
        return [ImageFormat::PNG, ImageFormat::JPEG, ImageFormat::TIFF];
    }

    /** RGBA8 or BGRA8, rows stored top to bottom. A bottom-to-top frame is a different layout. */
    public function writes(FormatSpec $format): bool
    {
        return $format->pixel_format === PixelFormat::ROW_MAJOR
            && $format->bit_depth === BitDepth::B32
            && $format->scan_direction === ScanDirection::TOP_TO_BOTTOM
            && ($format->channel_order === ChannelOrder::RGBA || $format->channel_order === ChannelOrder::BGRA)
            && is_null($format->palette);
    }

    public function info(ImageSource $source): ImageInfo
    {
        try {
            $facts = imgdec_info($source->bytes());
        } catch (\ImgdecException $e) {
            throw $this->translated($source, $e);
        }

        return new ImageInfo(
            ImageFormat::from($facts['format']),
            $facts['width'],
            $facts['height'],
            $facts['alpha'],
            $facts['frames'],
        );
    }

    public function decodeInto(ImageSource $source, PixelMemory $target, int $frame = 0): Region
    {
        if (! $this->writes($target->format)) {
            throw ImageException::noDecoder(ImageFormat::sniff($source->bytes()) ?? throw ImageException::unknownFormat(), $target->format);
        }

        $order = $target->format->channel_order === ChannelOrder::BGRA ? \IMGDEC_BGRA : \IMGDEC_RGBA;
        $capacity = $target->stride * $target->height;
        try {
            $written = imgdec_decode_into($source->bytes(), $target->address, $capacity, $target->stride, $order, $frame);
        } catch (\ImgdecException $e) {
            throw $this->translated($source, $e);
        }

        return new Region($written[0], $written[1], $written[2], $written[3]);
    }

    private function translated(ImageSource $source, \ImgdecException $e): ImageException
    {
        $format = ImageFormat::sniff($source->bytes()) ?? throw ImageException::unknownFormat();
        $message = $e->getMessage();
        if ($e->getCode() === \IMGDEC_TOO_LARGE && preg_match('/(\d+)x(\d+)/', $message, $size) === 1) {
            return ImageException::tooLarge($format, (int) $size[1], (int) $size[2]);
        }
        if (preg_match('/no frame (\d+); it has (\d+)/', $message, $frames) === 1) {
            return ImageException::frameOutOfRange($format, (int) $frames[1], (int) $frames[2]);
        }

        return ImageException::corrupt($format, $message);
    }
}
