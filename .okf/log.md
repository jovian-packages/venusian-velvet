# Log

## 2026-11-17

* An image end to end: a PNG file through `ImageLibrary` into a real `VelvetDevice`, drawn with `image()` and read back, on PHP frames with `lustrous-php` and C frames with `lustrous-c`. Velvet answers `Feature::IMAGES`. (tests/Images/ImagesEndToEndTest.php)
* Images: `lustrous-php` writes PNG and JPEG from gd, and TIFF in PHP, through the frame's own `writeRgba8()`. gd's 7-bit alpha is widened, associated TIFF alpha is divided out, and a frame that lends memory is refused by name. A 512×512 JPEG took 54 ms. `lustrous-c` stays first in the registration order when ext-imgdec can write an address. [parts](architecture/parts.md)
* Images: `lustrous-c` writes a PNG, JPEG or TIFF into a C frame's address through ext-imgdec. A wider stride's padding is left alone, a target shorter than the image is refused before a byte is written, and a bottom-to-top layout is not a layout it writes. [parts](architecture/parts.md)
* A decoded image painted with `image()` lands where it was placed. A C frame reports the written region with `drewInto()`; a wider row stride is read as padding, not pixels; a bottom-to-top frame still paints its first stored row at the top and emits its last row first. A PHP frame is written with `writeRgba8()` and refuses `drewInto()`. Velvet's surface lends that frame: no address from PHP, the ticket from C, and close records only the region given. [parts](architecture/parts.md)
* Created the bundle, README, AGENTS.md, SECURITY.md and `.github/workflows/tests.yml`. `composer.json` named venusian-metal's homepage and issues and carried a path repository to topside; both corrected, and topside is now a workbench path repository. [testing](runbooks/testing.md)
* Typography: variable fonts in PHP and C, CFF outlines in PHP and C, and faces with no outlines Lustrous reads refused and never offered by a catalog (catalog cache version 3). [typography](architecture/typography.md)
* Glyphs: `lustrous-php`, `lustrous-c` and `auto` glyph drivers on `velvet.glyphs`. [parts](architecture/parts.md)

## 2026-11-16

* Typography: the PHP font catalog, face and shaper (`lustrous-php`), then the C ones on ext-typog (`lustrous-c`). [typography](architecture/typography.md)
