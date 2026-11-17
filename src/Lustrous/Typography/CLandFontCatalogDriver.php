<?php

namespace Jovian\Engines\Velvet\Lustrous\Typography;

use Surface\Contracts\Typography\FontCatalog;
use Surface\Contracts\Typography\TypographyException;
use Surface\Typography\Catalogs\FontCatalogDriver;

/** 'lustrous-c': Lustrous's font catalog, its files described by ext-typog. */
class CLandFontCatalogDriver extends FontCatalogDriver
{
    private ?CLandFontCatalog $catalog = null;

    /**
     * @param  list<string>  $directories
     * @param  list<string>  $files
     * @param  array<string, list<string>>  $generics
     * @param  list<string>  $fallbacks
     * @param  string|null  $cache  where the catalog keeps its scan between processes
     *
     * @throws TypographyException When this PHP has no ext-typog, or one older than Typog::MINIMUM.
     */
    public function __construct(
        private readonly array $directories,
        private readonly array $files = [],
        private readonly array $generics = [],
        private readonly array $fallbacks = [],
        private readonly ?string $cache = null,
    ) {
        Typog::require('lustrous-c');
    }

    public function driver(): string
    {
        return 'lustrous-c';
    }

    public function catalog(): FontCatalog
    {
        return $this->catalog ??= new CLandFontCatalog($this->directories, $this->files, $this->generics, $this->fallbacks, $this->cache);
    }
}
