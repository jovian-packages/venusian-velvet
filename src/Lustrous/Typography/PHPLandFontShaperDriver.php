<?php

namespace Jovian\Engines\Velvet\Lustrous\Typography;

use Surface\Contracts\Typography\FontShaper;
use Surface\Typography\Shapers\FontShaperDriver;

/** 'lustrous-php': Lustrous's shaper in PHP. Always available. */
class PHPLandFontShaperDriver extends FontShaperDriver
{
    private ?PHPFontShaper $shaper = null;

    public function driver(): string
    {
        return 'lustrous-php';
    }

    public function shaper(): FontShaper
    {
        return $this->shaper ??= new PHPFontShaper();
    }
}
