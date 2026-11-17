<?php

namespace Jovian\Engines\Velvet\Lustrous\Typography;

use Surface\Contracts\Typography\FontFace;
use Surface\Contracts\Typography\FontSource;
use Surface\Typography\Faces\FontFaceDriver;

/** 'lustrous-php': Lustrous's font faces in PHP. Always available. */
class PHPLandFontFaceDriver extends FontFaceDriver
{
    /** @var array<string, PHPFontFace> */
    private array $faces = [];

    public function driver(): string
    {
        return 'lustrous-php';
    }

    public function face(FontSource $source): FontFace
    {
        return $this->faces[$source->key()] ??= new PHPFontFace($source);
    }

    public function release(): void
    {
        foreach ($this->faces as $face) {
            $face->close();
        }
        $this->faces = [];
    }
}
