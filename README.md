# jovian/venusian-velvet

Lustrous, Velvet's own renderer: the framebuffers, rasterizers, painters, strokers, raycasters, glyphs and typography drivers Velvet draws with on the CPU. Each part comes twice: `lustrous-php`, in plain PHP with no extension, and `lustrous-c`, in C through the house extensions. `auto` picks C where its extension is loaded and PHP everywhere else.

## Requirements

- Linux or macOS, PHP 8.4+.
- venusian/topside 0.11, which holds Velvet (`VelvetGE\`) and the contracts these drivers implement.
- For `lustrous-c`, by part (each optional; without it that part runs in PHP):

| Part | Extension |
|---|---|
| Framebuffers | ext-fb 0.11.1 |
| Rasterizers, strokers | ext-rasterize 0.11 |
| Glyphs | ext-rasterize with `RasterSpanStore`, drawing into frames in ext-fb memory |
| Painters | ext-paint 0.11 |
| Raycasters | ext-raycast 0.11 |
| Typography | ext-typog 0.11 |

ext-velvet, where installed, stands in for ext-fb, ext-rasterize and ext-paint.

## Install

```bash
composer require jovian/venusian-velvet
```

The provider registers every driver it can run. Velvet's parts are named in `config/velvet.php`; Typography's and Rendering's own managers in `config/typography.php` and `config/rendering.php`:

```php
// config/velvet.php
'cpu' => [
    'framebuffers' => ['default' => env('VELVET_FRAMEBUFFER', 'lustrous-c-full')],
    'rasterizers'  => ['default' => env('VELVET_RASTERIZER', 'auto')],
    'painters'     => ['default' => env('VELVET_PAINTER', 'auto')],
    'glyphs'       => ['default' => env('VELVET_GLYPHS', 'auto')],
],
```

## Usage

Nothing changes in application code: a canvas drawn by Velvet draws with Lustrous.

```php
use Surface\NutsAndBolts\Color;
use Surface\Typography\Typography;

$text = Typography::set("Hello\nworld", Typography::font('body'), width: 320);

$surface->render(fn ($g) => $g->text($text, 20, 40, Color::hex('#fff')));
```

What is registered depends on what the PHP has loaded. With every extension:

```php
use Jovian\Engines\Velvet\Providers\VenusianVelvetServiceProvider as Lustrous;

array_keys(Lustrous::rasterizationDrivers());   // ['lustrous-php', 'lustrous-c', 'auto']
```

and with none (`php -n`), `['lustrous-php', 'auto']`, where `auto` is the PHP driver.

A font face on its own:

```php
use Jovian\Engines\Velvet\Lustrous\Typography\PHPLandFontFaceDriver;
use Surface\Contracts\Typography\FontSource;

$face = (new PHPLandFontFaceDriver())->face(new FontSource('/System/Library/Fonts/Supplemental/Arial.ttf', 0));

$face->unitsPerEm();             // 2048
$glyph = $face->glyph(0x41);     // 36, 'A'
$face->advance($glyph);          // 1366, in font units
$face->metrics(14)->ascent;      // 12.673828125
$face->outline($glyph);          // a Path in font units, y up
```

## Behaviour

- **Where each part registers.** Framebuffers, rasterizers, painters, raycasters and glyphs register on Velvet's managers (`velvet.framebuffers`, `velvet.rasterizers`, `velvet.painters`, `velvet.raycasters`, `velvet.glyphs`). Strokers register on Rendering's `strokers`, the font catalog, face and shaper on Typography's `typography.catalogs`, `typography.faces` and `typography.shapers`, and the image decoders on Images' `images.decoders`: those serve every engine, not Velvet alone.
- **Framebuffers.** `lustrous-php` and `lustrous-c` make a full frame. A kind by name, `lustrous-php-<kind>` or `lustrous-c-<kind>`, makes that kind: `full`, `dirty`, `epaper`, `paged` or `ring`. Paged and ring frames take `velvet.cpu.framebuffers.lustrous.page_rows` and `frames`.
- **`auto`.** Chooses between Lustrous's two drivers and nothing else. For glyphs it chooses per frame: glyphs kept in C for a frame in C memory, in PHP for every other frame, so neither store crosses into the other.
- **Typography.** The PHP and C readers parse TrueType and CFF outlines, character maps, metrics, `kern`, and variable fonts (`fvar`, `avar` 1, `gvar`, `HVAR`, `MVAR`). The catalogs scan `typography.directories` and `typography.files`, match a request by CSS's font-matching rules, and keep their scan in `storage/framework/cache/fonts.json` unless `typography.cache` names a file.
- **Refusals.** Each throws the part's exception, naming what is wrong:
  - a `lustrous-c` driver made where its extension is missing or too old;
  - a font with CFF2 outlines, `avar` version 2, or no outlines Lustrous reads (Apple's `hvgl`, bitmap-only fonts). The catalogs never offer such a face;
  - a font whose tables are damaged or larger than their file.
- **Shaping.** Character to glyph, advances and the `kern` table: no ligatures, no `GPOS` kerning, no complex scripts. Typography's `harfbuzz` shaper (jovian/venusian-harfbuzz) does those.
- **Images.** Registered on Images' `images.decoders`, `lustrous-c` first where ext-imgdec can write an address, then `lustrous-php`. The C decoder writes the frame's memory. The PHP decoder writes PNG and JPEG from gd and TIFF in PHP, through the frame's own `writeRgba8()`, and refuses a frame that has memory. gd keeps alpha in 7 bits (0 opaque, 127 clear) and it is widened `255 − ((a << 1) + (a >> 6))`. A 512×512 JPEG took 54 ms this way.
- **Pixels stay where the frame keeps them.** A `lustrous-c` part works on C memory and hands back no pixels to PHP.

## Testing

See [.okf/runbooks/testing.md](.okf/runbooks/testing.md). The suite runs in a workbench beside venusian/topside, never in the repo root.

## License

MIT. See [LICENSE](LICENSE).
