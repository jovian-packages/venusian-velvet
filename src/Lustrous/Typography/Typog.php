<?php

namespace Jovian\Engines\Velvet\Lustrous\Typography;

use Surface\Contracts\Typography\TypographyException;
use TypogException;
use TypogFace;

/** What Lustrous's C typography drivers need of ext-typog. */
final class Typog
{
    /** The oldest ext-typog the drivers run on. */
    public const string MINIMUM = '0.11.0';

    /** Whether this PHP has an ext-typog of at least MINIMUM loaded. */
    public static function available(): bool
    {
        return class_exists(TypogFace::class, false) && version_compare((string) phpversion('typog'), self::MINIMUM, '>=');
    }

    /** @throws TypographyException When ext-typog is not loaded, or is older than MINIMUM. */
    public static function require(string $driver): void
    {
        if (! self::available()) {
            throw new TypographyException("The '{$driver}' typography driver needs ext-typog ".self::MINIMUM.' or later, which this PHP has not loaded.');
        }
    }

    /** ext-typog's refusal as Typography's, the reason kept. */
    public static function translated(TypogException $e): TypographyException
    {
        return new TypographyException($e->getMessage(), $e->getCode(), $e);
    }
}
