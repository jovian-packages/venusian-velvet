---
type: Module
title: Parts
description: Lustrous's drivers by part, where each registers, what lustrous-c needs, and how auto chooses.
resource: src/Providers/VenusianVelvetServiceProvider.php
tags: [velvet, lustrous, drivers]
status: draft
generated: { by: claude-opus/5.5, at: 2026-11-17T00:00:00-05:00 }
sources:
  - id: provider
    resource: src/Providers/VenusianVelvetServiceProvider.php
    title: VenusianVelvetServiceProvider
  - id: kinds
    resource: src/Lustrous/Framebuffers/StoreFramebufferDriver.php
    title: StoreFramebufferDriver
  - id: autoglyphs
    resource: src/Lustrous/Glyphs/AutoGlyphDriver.php
    title: AutoGlyphDriver
---

# Registration

The provider registers on boot. Each part's names come from a public static method, so tests and other code read the same list.[^provider]

| Part | Manager | `lustrous-c` registered when |
|---|---|---|
| Framebuffers | `velvet.framebuffers` | ext-fb or ext-velvet loaded |
| Rasterizers | `velvet.rasterizers` | ext-rasterize or ext-velvet loaded |
| Painters | `velvet.painters` | ext-paint or ext-velvet loaded |
| Strokers | `strokers` (Rendering) | class `RasterStroker` exists |
| Raycasters | `velvet.raycasters` | ext-raycast 0.11.0+ with `RaycastGrid::viewInto` |
| Glyphs | `velvet.glyphs` | `RasterSpanStore` exists and `RasterScanner::fills()` |
| Catalog, face, shaper | `typography.catalogs`, `.faces`, `.shapers` | ext-typog 0.11.0+ |
| Images | `images.decoders` | `imgdec_decode_into` exists (ext-imgdec 0.11.1) |

Strokers and typography register on Topside's managers, not Velvet's: they serve every engine.

# Framebuffers

`lustrous-php` and `lustrous-c` make a full frame; `lustrous-{php,c}-<kind>` always make that kind, kind one of `full`, `dirty`, `epaper`, `paged`, `ring`. Paged and ring frames take `velvet.cpu.framebuffers.lustrous.page_rows` (0 = one page) and `frames` (2). Any other kind name throws a `FramebufferException`.[^kinds]

A C frame's `memory()` is the ticket an image paint reads, stride included when a row is wider than its pixels. `drewInto()` records that region on a dirty frame. A PHP frame lends no ticket; `drewInto()` refuses it, and the image command reads what `writeRgba8()` wrote. The first stored row is the top of the paint. A bottom-to-top frame emits its last stored row first.

# Images

`lustrous-c` is `CLandImageDecoder`. It writes PNG, JPEG and TIFF into a top-to-bottom RGBA8 or BGRA8 `PixelMemory` through `imgdec_decode_into`. It is not a store decoder. A bottom-to-top layout is refused by `writes()`.

`lustrous-php` is `PHPLandImageDecoder`. It writes the same three formats through the frame's own `writeRgba8()`. PNG and JPEG come from gd, one pixel at a time: gd's alpha is 7 bits and is widened `255 − ((a << 1) + (a >> 6))`. TIFF is read in PHP (`TiffReader`), including the page that was asked for, and associated alpha is divided out. A frame that lends memory is refused by name. A 512×512 JPEG took 54 ms on this machine.

Images' config value `auto` tries the decoders in registration order. This package registers `lustrous-c` first when `imgdec_decode_into` exists, then `lustrous-php`.

# auto

For every part but glyphs and images, `auto` is `lustrous-c` where it is registered, else `lustrous-php`. Glyphs' `auto` decides per frame: C where the frame's memory is a C buffer ext-fb can blend into, PHP otherwise, so neither glyph store crosses into the other's pixels.[^autoglyphs] Images has no driver named `auto`; the Images manager expands that config word to the registered decoders.

[^provider]: VenusianVelvetServiceProvider
[^kinds]: StoreFramebufferDriver
[^autoglyphs]: AutoGlyphDriver
