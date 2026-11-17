<?php

namespace Jovian\Engines\Velvet\Lustrous\Images;

use Surface\Contracts\Images\StoreImageDecoder;
use Surface\Images\ImageDecoderDriver;

/** Registers Lustrous's PHP-store decoder. gd is required when a PNG or JPEG is actually read. */
final class PHPLandImageDecoderDriver extends ImageDecoderDriver
{
    public function driver(): string
    {
        return 'lustrous-php';
    }

    public function decoder(): StoreImageDecoder
    {
        return new PHPLandImageDecoder();
    }
}
