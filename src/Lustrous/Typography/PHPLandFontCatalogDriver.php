<?php

namespace Jovian\Engines\Velvet\Lustrous\Typography;

use Surface\Contracts\Typography\FontCatalog;
use Surface\Typography\Catalogs\FontCatalogDriver;

/** 'lustrous-php': Lustrous's font catalog in PHP. Always available. */
class PHPLandFontCatalogDriver extends FontCatalogDriver
{
    private ?PHPFontCatalog $catalog = null;

    /**
     * @param  list<string>  $directories
     * @param  list<string>  $files
     * @param  array<string, list<string>>  $generics
     * @param  list<string>  $fallbacks
     * @param  string|null  $cache  where the catalog keeps its scan between processes
     */
    public function __construct(
        private readonly array $directories,
        private readonly array $files = [],
        private readonly array $generics = [],
        private readonly array $fallbacks = [],
        private readonly ?string $cache = null,
    ) {}

    public function driver(): string
    {
        return 'lustrous-php';
    }

    public function catalog(): FontCatalog
    {
        return $this->catalog ??= new PHPFontCatalog($this->directories, $this->files, $this->generics, $this->fallbacks, $this->cache);
    }
}
