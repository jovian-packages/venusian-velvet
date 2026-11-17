<?php

namespace Jovian\Engines\Velvet\Lustrous\Typography;

use Surface\Contracts\Typography\FontShaper;
use Surface\Contracts\Typography\TypographyException;
use Surface\Typography\Shapers\FontShaperDriver;

/** 'lustrous-c': Lustrous's shaper, in ext-typog. */
class CLandFontShaperDriver extends FontShaperDriver
{
    private ?CLandFontShaper $shaper = null;

    /** @throws TypographyException When this PHP has no ext-typog, or one older than Typog::MINIMUM. */
    public function __construct()
    {
        Typog::require('lustrous-c');
    }

    public function driver(): string
    {
        return 'lustrous-c';
    }

    public function shaper(): FontShaper
    {
        return $this->shaper ??= new CLandFontShaper();
    }
}
