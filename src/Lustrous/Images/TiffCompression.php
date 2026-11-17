<?php

namespace Jovian\Engines\Velvet\Lustrous\Images;

/** The TIFF compressions lustrous-php reads. The values are the TIFF Compression tag. */
enum TiffCompression: int
{
    case NONE = 1;
    case LZW = 5;
    case DEFLATE = 8;
    case ADOBE_DEFLATE = 32946;
    case PACKBITS = 32773;
}
