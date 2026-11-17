<?php

namespace Jovian\Engines\Velvet\Lustrous\Images;

use Surface\Contracts\Images\ImageException;
use Surface\Contracts\Images\ImageFormat;
use Surface\Contracts\Images\ImageInfo;
use Surface\Contracts\Images\ImageSource;
use Surface\Contracts\Images\StoreImageDecoder;
use Surface\Contracts\Rendering\Framebuffers\BitDepth;
use Surface\Contracts\Rendering\Framebuffers\ChannelOrder;
use Surface\Contracts\Rendering\Framebuffers\FormatSpec;
use Surface\Contracts\Rendering\Framebuffers\InstanceThatManagesAFramebuffer;
use Surface\Contracts\Rendering\Framebuffers\PixelFormat;
use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\Contracts\Rendering\Framebuffers\ScanDirection;

/**
 * Lustrous's PHP-store decoder. PNG and JPEG come from gd, one pixel at a time, and TIFF from
 * TiffReader. The pixels are written with the frame's own writeRgba8(). A frame that lends memory
 * is refused: that frame is written at its address, and these pixels would otherwise become a string.
 */
final class PHPLandImageDecoder implements StoreImageDecoder
{
    public function driver(): string
    {
        return 'lustrous-php';
    }

    public function formats(): array
    {
        return [ImageFormat::PNG, ImageFormat::JPEG, ImageFormat::TIFF];
    }

    /** 32-bit RGBA or BGRA. writeRgba8() maps the bytes into the frame, scan included. */
    public function writes(FormatSpec $format): bool
    {
        return $format->pixel_format === PixelFormat::ROW_MAJOR
            && $format->bit_depth === BitDepth::B32
            && ($format->channel_order === ChannelOrder::RGBA || $format->channel_order === ChannelOrder::BGRA)
            && ($format->scan_direction === ScanDirection::TOP_TO_BOTTOM || $format->scan_direction === ScanDirection::BOTTOM_TO_TOP)
            && is_null($format->palette);
    }

    public function info(ImageSource $source): ImageInfo
    {
        $bytes = $source->bytes();
        $format = ImageFormat::sniff($bytes) ?? throw ImageException::unknownFormat();

        return match ($format) {
            ImageFormat::PNG => $this->pngInfo($bytes),
            ImageFormat::JPEG => $this->jpegInfo($bytes),
            ImageFormat::TIFF => $this->tiffInfo($bytes),
        };
    }

    public function decodeIntoStore(ImageSource $source, InstanceThatManagesAFramebuffer $target, int $frame = 0): Region
    {
        if (! is_null($target->memory())) {
            throw new ImageException('lustrous-php writes through the PHP store, and this frame has its own memory.');
        }
        $bytes = $source->bytes();
        $format = ImageFormat::sniff($bytes) ?? throw ImageException::unknownFormat();
        if (! $this->writes($target->hostFormat())) {
            throw ImageException::noDecoder($format, $target->hostFormat());
        }
        $info = $this->info($source);
        if ($frame < 0 || $frame >= $info->frames) {
            throw ImageException::frameOutOfRange($format, $frame, $info->frames);
        }
        if ($info->width !== $target->viewportWidth() || $info->height !== $target->viewportHeight()) {
            throw ImageException::corrupt($format, "it is {$info->width}x{$info->height}, and the frame is {$target->viewportWidth()}x{$target->viewportHeight()}.");
        }

        [$rgba, $width, $height] = $format === ImageFormat::TIFF
            ? TiffReader::rgba8($bytes, $frame)
            : $this->gdRgba8($format, $bytes);
        $target->writeRgba8($rgba, $width, $height);

        return new Region(0, 0, $width, $height);
    }

    private function pngInfo(string $bytes): ImageInfo
    {
        if (strlen($bytes) < 29 || ! str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            throw ImageException::corrupt(ImageFormat::PNG, 'the data stops before the image does.');
        }
        $width = unpack('N', $bytes, 16)[1];
        $height = unpack('N', $bytes, 20)[1];
        $colour = ord($bytes[25]);
        $alpha = $colour === 4 || $colour === 6;
        $at = 8;
        while (! $alpha && $at + 12 <= strlen($bytes)) {
            $length = unpack('N', $bytes, $at)[1];
            $type = substr($bytes, $at + 4, 4);
            if ($type === 'tRNS') {
                $alpha = true;
                break;
            }
            if ($type === 'IDAT' || $type === 'IEND' || $at + 12 + $length > strlen($bytes)) {
                break;
            }
            $at += 12 + $length;
        }

        return new ImageInfo(ImageFormat::PNG, $width, $height, $alpha, 1);
    }

    private function jpegInfo(string $bytes): ImageInfo
    {
        $length = strlen($bytes);
        if ($length < 4 || ! str_starts_with($bytes, "\xFF\xD8")) {
            throw ImageException::corrupt(ImageFormat::JPEG, 'the data stops before the image does.');
        }
        $i = 2;
        while ($i + 1 < $length) {
            if (ord($bytes[$i]) !== 0xFF) {
                throw ImageException::corrupt(ImageFormat::JPEG, 'the data stops before the image does.');
            }
            while ($i < $length && ord($bytes[$i]) === 0xFF) {
                $i++;
            }
            if ($i >= $length) {
                break;
            }
            $marker = ord($bytes[$i]);
            $i++;
            if ($marker === 0xD8 || $marker === 0xD9 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                continue;
            }
            if ($i + 1 >= $length) {
                break;
            }
            $segment = unpack('n', $bytes, $i)[1];
            if ($marker >= 0xC0 && $marker <= 0xCF && $marker !== 0xC4 && $marker !== 0xC8 && $marker !== 0xCC) {
                if ($segment < 8 || $i + 6 >= $length) {
                    throw ImageException::corrupt(ImageFormat::JPEG, 'the data stops before the image does.');
                }

                return new ImageInfo(ImageFormat::JPEG, unpack('n', $bytes, $i + 5)[1], unpack('n', $bytes, $i + 3)[1], false, 1);
            }
            if ($segment < 2) {
                throw ImageException::corrupt(ImageFormat::JPEG, 'the data stops before the image does.');
            }
            $i += $segment;
        }

        throw ImageException::corrupt(ImageFormat::JPEG, 'the data stops before the image does.');
    }

    private function tiffInfo(string $bytes): ImageInfo
    {
        $frames = TiffDirectory::frames($bytes);
        $ifd = TiffDirectory::read($bytes);
        $alpha = $ifd->has(338) && in_array($ifd->numbers(338)[0] ?? 0, [1, 2], true);

        return new ImageInfo(ImageFormat::TIFF, $ifd->number(256), $ifd->number(257), $alpha, $frames);
    }

    /**
     * gd's alpha is 7 bits, 0 opaque and 127 clear. Widened the way gd's own PNG writer widens it:
     * 255 − ((a << 1) + (a >> 6)). So 0 stays opaque (255), 1 becomes 253, 64 becomes 126, 127 becomes 0.
     *
     * @return array{string, int, int}
     */
    private function gdRgba8(ImageFormat $format, string $bytes): array
    {
        extension_loaded('gd') || throw new ImageException('lustrous-php reads '.strtoupper($format->value).' through gd, which this PHP does not load.');
        $reasons = [];
        set_error_handler(function (int $level, string $message) use (&$reasons): bool {
            $reasons[] = preg_replace('/^imagecreatefromstring\(\): /', '', $message) ?? $message;

            return true;
        });
        try {
            $image = imagecreatefromstring($bytes);
        } finally {
            restore_error_handler();
        }
        if (! $image instanceof \GdImage) {
            throw ImageException::corrupt($format, 'gd could not decode it ('.(implode('; ', $reasons) ?: 'no reason given').').');
        }
        $width = imagesx($image);
        $height = imagesy($image);
        $palette = ! imageistruecolor($image);
        $colours = [];
        if ($palette) {
            for ($i = 0, $n = imagecolorstotal($image); $i < $n; $i++) {
                ['red' => $red, 'green' => $green, 'blue' => $blue, 'alpha' => $alpha] = imagecolorsforindex($image, $i);
                $colours[$i] = chr($red).chr($green).chr($blue).chr(self::widen($alpha));
            }
        }
        $out = '';
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $colour = imagecolorat($image, $x, $y);
                if ($palette) {
                    $out .= $colours[$colour] ?? "\0\0\0\0";
                    continue;
                }
                $alpha = ($colour >> 24) & 0x7F;
                $out .= chr(($colour >> 16) & 0xFF).chr(($colour >> 8) & 0xFF).chr($colour & 0xFF).chr(self::widen($alpha));
            }
        }

        return [$this->clearTrns($format, $bytes, $out), $width, $height];
    }

    /** gd leaves an RGB or grey PNG's tRNS colour opaque. That colour is made clear, matched on the high byte. */
    private function clearTrns(ImageFormat $format, string $bytes, string $rgba): string
    {
        if ($format !== ImageFormat::PNG || strlen($bytes) < 26) {
            return $rgba;
        }
        $colourType = ord($bytes[25]);
        $depth = ord($bytes[24]);
        if ($colourType !== 0 && $colourType !== 2) {
            return $rgba;
        }
        $trns = null;
        $at = 8;
        while ($at + 12 <= strlen($bytes)) {
            $length = unpack('N', $bytes, $at)[1];
            $type = substr($bytes, $at + 4, 4);
            if ($type === 'tRNS') {
                $trns = substr($bytes, $at + 8, $length);
                break;
            }
            if ($type === 'IDAT' || $at + 12 + $length > strlen($bytes)) {
                break;
            }
            $at += 12 + $length;
        }
        if (is_null($trns) || strlen($trns) < ($colourType === 2 ? 6 : 2)) {
            return $rgba;
        }
        $sample = function (int $i) use ($trns, $depth): string {
            $value = unpack('n', $trns, $i * 2)[1];

            return chr($depth === 16 ? $value >> 8 : $value & 0xFF);
        };
        if ($colourType === 0) {
            $value = unpack('n', $trns)[1];
            if ($depth < 8) {
                $value = intdiv($value * 255, (1 << $depth) - 1);
            }
            $key = str_repeat(chr($depth === 16 ? $value >> 8 : $value & 0xFF), 3);
        } else {
            $key = $sample(0).$sample(1).$sample(2);
        }
        $pixels = str_split($rgba, 4);

        return implode('', str_replace($key."\xff", $key."\0", $pixels));
    }

    private static function widen(int $alpha): int
    {
        return 255 - (($alpha << 1) + ($alpha >> 6));
    }
}
