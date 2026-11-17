<?php

namespace Jovian\Engines\Velvet\Lustrous\Typography;

use Surface\Contracts\Typography\TypographyException;

/**
 * One face of an OpenType or TrueType file (or of a collection), opened for reading: its table
 * directory, and its tables read from the file when asked for. The file stays open until close().
 *
 * Every read is checked against what the file holds: a font file is bytes this project did not
 * write, so a short or broken one is a TypographyException, never a warning or a wrong number.
 */
final class OpenTypeFile
{
    /** sfnt versions: TrueType outlines, CFF outlines, Apple's TrueType. */
    private const array VERSIONS = ["\x00\x01\x00\x00", 'OTTO', 'true'];

    /** @var resource|null */
    private $handle;

    /** @var array<string, array{int, int}> tag => [offset, length] */
    private array $tables = [];

    /** @var array<string, string> */
    private array $read = [];

    /** @throws TypographyException When the file cannot be opened, is not a font, or has no such face. */
    public function __construct(
        public readonly string $path,
        public readonly int $index = 0,
    ) {
        $handle = is_file($path) ? @fopen($path, 'rb') : false;
        if ($handle === false) {
            throw new TypographyException("The font file {$path} cannot be opened.");
        }
        $this->handle = $handle;

        $start = 0;
        $header = $this->bytes(0, 12);
        if (substr($header, 0, 4) === 'ttcf') {
            $faces = self::collectionFaces($handle, $path, $header);
            if ($index >= $faces) {
                throw new TypographyException("The font collection {$path} has {$faces} faces; there is no face {$index}.");
            }
            $start = self::u32($this->bytes(12 + 4 * $index, 4), 0);
            $header = $this->bytes($start, 12);
        } elseif ($index !== 0) {
            throw new TypographyException("The font file {$path} is not a collection; its one face is 0, not {$index}.");
        }
        if (! in_array(substr($header, 0, 4), self::VERSIONS, true)) {
            throw new TypographyException("The file {$path} is not an OpenType or TrueType font.");
        }
        $count = self::u16($header, 4);
        $directory = $this->bytes($start + 12, 16 * $count);
        for ($i = 0; $i < $count; $i++) {
            $this->tables[substr($directory, 16 * $i, 4)] = [self::u32($directory, 16 * $i + 8), self::u32($directory, 16 * $i + 12)];
        }
    }

    /**
     * How many faces the file holds: a collection's count, or 1.
     *
     * @throws TypographyException When the file cannot be opened or is not a font.
     */
    public static function faces(string $path): int
    {
        $handle = is_file($path) ? @fopen($path, 'rb') : false;
        if ($handle === false) {
            throw new TypographyException("The font file {$path} cannot be opened.");
        }
        $header = (string) stream_get_contents($handle, 12, 0);
        try {
            if (strlen($header) < 12) {
                throw new TypographyException("The file {$path} is too short to be a font.");
            }
            if (substr($header, 0, 4) === 'ttcf') {
                return self::collectionFaces($handle, $path, $header);
            }
        } finally {
            fclose($handle);
        }
        if (! in_array(substr($header, 0, 4), self::VERSIONS, true)) {
            throw new TypographyException("The file {$path} is not an OpenType or TrueType font.");
        }

        return 1;
    }

    /**
     * The face count of a collection header. A file has room for at most size / 32 faces, FreeType's
     * bound: each face needs its 4-byte offset, a 12-byte header and one 16-byte table record. A larger
     * count is a broken header, which would otherwise have every face of it opened in turn.
     *
     * @param resource $handle
     *
     * @throws TypographyException When the count is larger.
     */
    private static function collectionFaces($handle, string $path, string $header): int
    {
        $faces = self::u32($header, 8);
        $size = fstat($handle)['size'];
        if ($faces > intdiv($size, 32)) {
            throw new TypographyException(sprintf('The font collection %s claims %d faces; its %d bytes have room for %d.', $path, $faces, $size, intdiv($size, 32)));
        }

        return $faces;
    }

    public function has(string $tag): bool
    {
        return isset($this->tables[$tag]);
    }

    /** The whole table, read once and kept; null when the face has none. */
    public function table(string $tag): ?string
    {
        if (! isset($this->tables[$tag])) {
            return null;
        }

        return $this->read[$tag] ??= $this->bytes(...$this->tables[$tag]);
    }

    /**
     * Part of a table, read from the file and not kept: for glyph data, which is read a glyph at a
     * time.
     *
     * @throws TypographyException When the part reaches past the table.
     */
    public function part(string $tag, int $from, int $length): string
    {
        [$offset, $size] = $this->tables[$tag] ?? throw new TypographyException("{$this->path} has no '{$tag}' table.");
        if ($from < 0 || $length < 0 || $from + $length > $size) {
            throw new TypographyException("{$this->path}: bytes {$from}..".($from + $length)." are outside its '{$tag}' table of {$size}.");
        }

        return $this->bytes($offset + $from, $length);
    }

    public function close(): void
    {
        if (! is_null($this->handle)) {
            fclose($this->handle);
            $this->handle = null;
        }
        $this->read = [];
    }

    /** @throws TypographyException When the file is closed or holds fewer bytes than asked for. */
    private function bytes(int $offset, int $length): string
    {
        if (is_null($this->handle)) {
            throw new TypographyException("The font file {$this->path} is closed.");
        }
        if ($length === 0) {
            return '';
        }
        $bytes = stream_get_contents($this->handle, $length, $offset);
        if ($bytes === false || strlen($bytes) !== $length) {
            throw new TypographyException("The font file {$this->path} ends before byte ".($offset + $length).'.');
        }

        return $bytes;
    }

    /** @throws TypographyException When the data ends before the value. */
    public static function u16(string $data, int $at): int
    {
        if ($at < 0 || $at + 2 > strlen($data)) {
            throw new TypographyException('A font table ends before a value it should hold.');
        }

        return (ord($data[$at]) << 8) | ord($data[$at + 1]);
    }

    /** @throws TypographyException When the data ends before the value. */
    public static function i16(string $data, int $at): int
    {
        $value = self::u16($data, $at);

        return $value >= 0x8000 ? $value - 0x10000 : $value;
    }

    /** @throws TypographyException When the data ends before the value. */
    public static function u32(string $data, int $at): int
    {
        if ($at < 0 || $at + 4 > strlen($data)) {
            throw new TypographyException('A font table ends before a value it should hold.');
        }

        return unpack('N', $data, $at)[1];
    }
}
