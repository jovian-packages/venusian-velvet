<?php

namespace Jovian\Engines\Velvet\Lustrous\Typography;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Surface\Contracts\Typography\FontCatalog;
use Surface\Contracts\Typography\FontRequest;
use Surface\Contracts\Typography\FontSlant;
use Surface\Contracts\Typography\FontSource;
use Surface\Contracts\Typography\TypographyException;

/**
 * What Lustrous's two font catalogs share: every .ttf, .otf, .ttc and .otc file under the
 * directories, plus the files given, each face described once (by the subclass: in PHP or through
 * ext-typog) and kept in a cache file when given one, so a later process describes only the files
 * whose size or modified time changed. Both write the same descriptions, so they share a cache file.
 * A file in a directory that is not a readable font is passed over; a file given by name must be
 * one. A face whose outlines Lustrous cannot draw (no 'glyf' or 'CFF ': CFF2, bitmaps only, Apple's
 * 'hvgl') is described, but never offered as a match or a fallback.
 *
 * A request is matched as CSS matches a font: the first of its families the catalog has (a generic
 * family standing for the families configured for it, and 'sans-serif' after them all), then in
 * that family the nearest slant, then the nearest weight by CSS's order, then the width nearest
 * normal. A character the request's face lacks is looked for in the request's families, then in
 * the fallback families, then in every family, by name.
 *
 * A variable face ('fvar') is matched as CSS Fonts 4 matches one: its weight is the request's,
 * clamped to its 'wght' range, and competes by CSS's order like any other; it offers italic through
 * 'ital' 1, oblique through 'slnt' -14 (CSS's default oblique angle; 'slnt' counts the other way),
 * clamped to the axis, and upright through 'ital' 0 and 'slnt' 0. The face it answers names those
 * coordinates: 'wght', and 'ital' or 'slnt' where it has them. An axis the request does not speak
 * to is left out, at its default.
 */
abstract class LustrousFontCatalog implements FontCatalog
{
    protected const array EXTENSIONS = ['ttf', 'otf', 'ttc', 'otc'];

    /** Raised when what the cache keeps for a face changes: 2 added the face's variation axes, 3 its outline table. */
    protected const int CACHE_VERSION = 3;

    /** The outline tables Lustrous's faces draw. */
    private const array DRAWN = ['glyf', 'CFF '];

    /** CSS's oblique angle when none is given, 14 degrees; OpenType's 'slnt' counts it as -14. */
    private const float OBLIQUE = -14.0;

    /** @var list<array{FontSource, int, FontSlant, int, array<string, array{float, float, float}>}>|null source, weight, slant, width class, axes (tag => minimum, default, maximum) */
    private ?array $faces = null;

    /** @var array<string, list<int>> family, lower case => indexes into $faces */
    private array $families = [];

    /** @var array<string, FontSource|null> */
    private array $covering = [];

    /**
     * @param  list<string>  $directories
     * @param  list<string>  $files
     * @param  array<string, list<string>>  $generics  generic family => the families it stands for
     * @param  list<string>  $fallbacks
     * @param  string|null  $cache  a file the scan is kept in between processes; null keeps none
     */
    public function __construct(
        private readonly array $directories,
        private readonly array $files = [],
        private readonly array $generics = [],
        private readonly array $fallbacks = [],
        private readonly ?string $cache = null,
    ) {}

    public function match(FontRequest $request): FontSource
    {
        $this->scan();
        foreach ($this->named($request) as $family) {
            if (isset($this->families[$family])) {
                return $this->source(...$this->nearest($this->families[$family], $request));
            }
        }
        if ($this->faces === []) {
            throw new TypographyException('The font catalog found no fonts in '.implode(', ', $this->directories).'.');
        }
        $families = array_keys($this->families);
        sort($families, SORT_STRING);

        return $this->source(...$this->nearest($this->families[$families[0]], $request));
    }

    public function covering(FontRequest $request, int $codepoint): ?FontSource
    {
        $key = $request->key().'|'.$codepoint;
        if (array_key_exists($key, $this->covering)) {
            return $this->covering[$key];
        }
        $this->scan();
        $everyFamily = array_keys($this->families);
        sort($everyFamily, SORT_STRING);
        $tried = [];
        foreach ([...$this->named($request), ...array_map('mb_strtolower', $this->fallbacks), ...$everyFamily] as $family) {
            if (! isset($this->families[$family]) || isset($tried[$family])) {
                continue;
            }
            $tried[$family] = true;
            [$face, $coordinates] = $this->nearest($this->families[$family], $request);
            if ($this->covers($this->faces[$face][0], $codepoint)) {
                return $this->covering[$key] = $this->source($face, $coordinates);
            }
        }

        return $this->covering[$key] = null;
    }

    /**
     * The faces of one file, as the cache keeps them: index, family, weight, slant (a FontSlant
     * value), width class, its 'fvar' axes (tag, minimum, default, maximum; none for a static
     * face), and its outline table ('glyf', 'CFF ', 'CFF2', or '' for none of them). A file in a font directory that is not a readable font has none; a broken face in it is
     * passed over.
     *
     * @return list<array{int, string, int, string, int, list<array{string, float, float, float}>, string}>
     *
     * @throws TypographyException When a file given by name ($named) is not a readable font.
     */
    abstract protected function describe(string $path, bool $named): array;

    /** Whether the face has a glyph for the codepoint; false for a face that cannot be read. */
    abstract protected function covers(FontSource $source, int $codepoint): bool;

    /**
     * The request's families in order, lower case, generic ones replaced by what they stand for,
     * then what 'sans-serif' stands for.
     *
     * @return list<string>
     */
    private function named(FontRequest $request): array
    {
        $generics = array_change_key_case($this->generics, CASE_LOWER);
        $named = [];
        foreach ([...$request->families, 'sans-serif'] as $family) {
            $family = mb_strtolower(trim($family));
            foreach ($generics[$family] ?? [$family] as $name) {
                $named[] = mb_strtolower($name);
            }
        }

        return array_values(array_unique($named));
    }

    /**
     * The face among these nearest the request, and the coordinates it is drawn at: CSS's slant
     * order (a variable face offering the slants its 'ital' and 'slnt' reach), then its weight order
     * (a variable face's weight the request's, clamped to its 'wght'), then the width class nearest
     * normal (5), then the first found.
     *
     * @param  list<int>  $faces
     * @return array{int, array<string, float>}
     */
    private function nearest(array $faces, FontRequest $request): array
    {
        $slants = match ($request->slant) {
            FontSlant::UPRIGHT => [FontSlant::UPRIGHT, FontSlant::OBLIQUE, FontSlant::ITALIC],
            FontSlant::ITALIC => [FontSlant::ITALIC, FontSlant::OBLIQUE, FontSlant::UPRIGHT],
            FontSlant::OBLIQUE => [FontSlant::OBLIQUE, FontSlant::ITALIC, FontSlant::UPRIGHT],
        };
        $offered = [];
        foreach ($slants as $slant) {
            foreach ($faces as $face) {
                $coordinates = self::slanted($this->faces[$face], $slant);
                if (! is_null($coordinates)) {
                    $offered[$face] = $coordinates;
                }
            }
            if ($offered !== []) {
                break;
            }
        }
        $best = null;
        $bestRank = null;
        foreach ($offered as $face => $coordinates) {
            [, $weight, , $width, $axes] = $this->faces[$face];
            if (isset($axes['wght'])) {
                $weight = (int) round(max($axes['wght'][0], min($axes['wght'][2], (float) $request->weight)));
            }
            $rank = [...self::weightRank($request->weight, $weight), abs($width - 5)];
            if (is_null($bestRank) || $rank < $bestRank) {
                [$best, $bestRank] = [$face, $rank];
            }
        }
        $coordinates = $offered[$best];
        $axes = $this->faces[$best][4];
        if (isset($axes['wght'])) {
            $coordinates['wght'] = max($axes['wght'][0], min($axes['wght'][2], (float) $request->weight));
        }

        return [$best, $coordinates];
    }

    /**
     * The coordinates a face is drawn at for a slant, or null when it does not offer it: italic
     * through 'ital' 1; oblique through 'slnt' at CSS's default angle, clamped; upright through
     * 'ital' 0 and 'slnt' 0 where it has them. Its own slant it offers as it is, so every face
     * offers one of the three.
     *
     * @param  array{FontSource, int, FontSlant, int, array<string, array{float, float, float}>}  $face
     * @return array<string, float>|null
     */
    private static function slanted(array $face, FontSlant $slant): ?array
    {
        [, , $own, , $axes] = $face;
        [$ital, $slnt] = [$axes['ital'] ?? null, $axes['slnt'] ?? null];
        $reaches = fn (?array $axis, float $value) => ! is_null($axis) && $axis[0] <= $value && $value <= $axis[2];

        return match ($slant) {
            FontSlant::ITALIC => match (true) {
                $reaches($ital, 1.0) => ['ital' => 1.0],
                $own === FontSlant::ITALIC => [],
                default => null,
            },
            FontSlant::OBLIQUE => match (true) {
                ! is_null($slnt) && $slnt[0] < 0 => ['slnt' => max($slnt[0], self::OBLIQUE)],
                $own === FontSlant::OBLIQUE => [],
                default => null,
            },
            FontSlant::UPRIGHT => match (true) {
                (! is_null($ital) || ! is_null($slnt)) && (is_null($ital) || $reaches($ital, 0.0)) && (is_null($slnt) || $reaches($slnt, 0.0)) => array_filter(
                    ['ital' => is_null($ital) ? null : 0.0, 'slnt' => is_null($slnt) ? null : 0.0],
                    fn (?float $value) => ! is_null($value),
                ),
                $own === FontSlant::UPRIGHT => [],
                default => null,
            },
        };
    }

    /** The face's source at the coordinates. */
    private function source(int $face, array $coordinates): FontSource
    {
        $source = $this->faces[$face][0];

        return $coordinates === [] ? $source : new FontSource($source->path, $source->index, $coordinates);
    }

    /**
     * A face's 'fvar' axes as the cache keeps them: tag, minimum, default, maximum, in the axis's
     * own units. None for a face without 'fvar'.
     *
     * @return list<array{string, float, float, float}>
     *
     * @throws TypographyException When 'fvar' is broken.
     */
    protected static function axesOf(OpenTypeFile $file, string $key): array
    {
        $fvar = $file->table('fvar');
        if (is_null($fvar)) {
            return [];
        }

        return array_map(
            fn (array $axis) => [$axis[0], $axis[1] / 65536, $axis[2] / 65536, $axis[3] / 65536],
            FontVariations::axes($fvar, $key),
        );
    }

    /**
     * Where a face's weight comes in CSS's search from the wanted one: lower is sooner. From 400 to
     * 500, heavier up to 500 first, then lighter, then heavier past 500; under 400, lighter first;
     * over 500, heavier first.
     *
     * @return array{int, int}
     */
    private static function weightRank(int $wanted, int $weight): array
    {
        if ($weight === $wanted) {
            return [0, 0];
        }
        if ($wanted >= 400 && $wanted <= 500) {
            return match (true) {
                $weight > $wanted && $weight <= 500 => [1, $weight - $wanted],
                $weight < $wanted => [2, $wanted - $weight],
                default => [3, $weight - $wanted],
            };
        }
        if ($wanted < 400) {
            return $weight < $wanted ? [1, $wanted - $weight] : [2, $weight - $wanted];
        }

        return $weight > $wanted ? [1, $weight - $wanted] : [2, $wanted - $weight];
    }

    /**
     * Finds every face: the files it was given, then every font file under its directories. A file
     * whose size and modified time match the cache is not read again.
     *
     * @throws TypographyException When a file the catalog was given by name is not a readable font.
     */
    private function scan(): void
    {
        if (! is_null($this->faces)) {
            return;
        }
        $this->faces = [];
        $found = [];
        foreach ($this->directories as $directory) {
            if (! is_dir($directory)) {
                continue;
            }
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file->isFile() && in_array(strtolower($file->getExtension()), self::EXTENSIONS, true)) {
                    $found[$file->getPathname()] = true;
                }
            }
        }
        $found = array_keys($found);
        sort($found, SORT_STRING);

        $cached = $this->cached();
        $fresh = [];
        $named = array_fill_keys($this->files, true);
        foreach ([...$this->files, ...array_diff($found, $this->files)] as $path) {
            $stat = @stat($path);
            if ($stat === false) {
                if (isset($named[$path])) {
                    throw new TypographyException("The font file {$path} does not exist.");
                }
                continue;
            }
            $entry = $cached[$path] ?? null;
            $faces = is_array($entry) && ($entry[0] ?? null) === $stat['mtime'] && ($entry[1] ?? null) === $stat['size']
                ? $entry[2]
                : $this->describe($path, isset($named[$path]));
            $fresh[$path] = [$stat['mtime'], $stat['size'], $faces];
            foreach ($faces as [$index, $family, $weight, $slant, $width, $axes, $outlines]) {
                if (! in_array($outlines, self::DRAWN, true)) {
                    continue;   // described and kept, but nothing Lustrous can draw
                }
                $this->families[mb_strtolower(trim($family))][] = count($this->faces);
                $byTag = [];
                foreach ($axes as [$tag, $min, $default, $max]) {
                    $byTag[$tag] ??= [(float) $min, (float) $default, (float) $max];
                }
                $this->faces[] = [new FontSource($path, $index), $weight, FontSlant::from($slant), $width, $byTag];
            }
        }
        if ($fresh !== $cached) {
            $this->keep($fresh);
        }
    }

    /**
     * What the cache file holds: path => [modified time, size, faces]. Nothing when there is no
     * cache, or it is unreadable or of another version: the scan then reads every file.
     *
     * @return array<string, array{int, int, list<array{int, string, int, string, int, list<array{string, float, float, float}>}>}>
     */
    private function cached(): array
    {
        if (is_null($this->cache) || ! is_file($this->cache)) {
            return [];
        }
        $kept = json_decode((string) @file_get_contents($this->cache), true);

        return is_array($kept) && ($kept['version'] ?? null) === self::CACHE_VERSION && is_array($kept['files'] ?? null) ? $kept['files'] : [];
    }

    /**
     * Writes the cache, whole, through a file renamed into place. A cache that cannot be written is
     * left: the next process scans again.
     *
     * @param  array<string, array{int, int, list<array{int, string, int, string, int, list<array{string, float, float, float}>}>}>  $files
     */
    private function keep(array $files): void
    {
        if (is_null($this->cache)) {
            return;
        }
        $directory = dirname($this->cache);
        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            return;
        }
        $temporary = $this->cache.'.'.getmypid().'.tmp';
        if (@file_put_contents($temporary, json_encode(['version' => self::CACHE_VERSION, 'files' => $files], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) !== false) {
            @rename($temporary, $this->cache) || @unlink($temporary);
        }
    }
}
