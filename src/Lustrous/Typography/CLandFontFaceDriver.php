<?php

namespace Jovian\Engines\Velvet\Lustrous\Typography;

use Surface\Contracts\Typography\FontFace;
use Surface\Contracts\Typography\FontSource;
use Surface\Contracts\Typography\TypographyException;
use Surface\Typography\Faces\FontFaceDriver;

/** 'lustrous-c': Lustrous's font faces, read by ext-typog. */
class CLandFontFaceDriver extends FontFaceDriver
{
    /** @var array<string, CLandFontFace> */
    private array $faces = [];

    /** @throws TypographyException When this PHP has no ext-typog, or one older than Typog::MINIMUM. */
    public function __construct()
    {
        Typog::require('lustrous-c');
    }

    public function driver(): string
    {
        return 'lustrous-c';
    }

    public function face(FontSource $source): FontFace
    {
        return $this->faces[$source->key()] ??= new CLandFontFace($source);
    }

    public function release(): void
    {
        foreach ($this->faces as $face) {
            $face->close();
        }
        $this->faces = [];
    }
}
