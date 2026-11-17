<?php

namespace Jovian\Engines\Velvet\Lustrous\Images;

use Surface\Contracts\Images\ImageDecoder;
use Surface\Contracts\Images\ImageException;
use Surface\Images\ImageDecoderDriver;

/**
 * Registers Lustrous's address decoder where ext-imgdec can write a frame at an address.
 */
final class CLandImageDecoderDriver extends ImageDecoderDriver
{
    /**
     * @throws ImageException When this PHP has no imgdec_decode_into().
     */
    public function __construct()
    {
        function_exists('imgdec_decode_into') || throw new ImageException('ext-imgdec cannot write a frame at an address in this PHP.');
    }

    public function driver(): string
    {
        return 'lustrous-c';
    }

    public function decoder(): ImageDecoder
    {
        return new CLandImageDecoder();
    }
}
