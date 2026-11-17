---
type: Module
title: Typography
description: Lustrous's PHP and C font readers, catalogs and shaper.
resource: src/Lustrous/Typography/
tags: [typography, fonts, opentype, cff]
status: draft
generated: { by: claude-opus/5.5, at: 2026-11-17T00:00:00-05:00 }
sources:
  - id: file
    resource: src/Lustrous/Typography/OpenTypeFile.php
    title: OpenTypeFile
  - id: phpface
    resource: src/Lustrous/Typography/PHPFontFace.php
    title: PHPFontFace
  - id: cff
    resource: src/Lustrous/Typography/CompactFontFormat.php
    title: CompactFontFormat
  - id: variations
    resource: src/Lustrous/Typography/FontVariations.php
    title: FontVariations
  - id: catalog
    resource: src/Lustrous/Typography/LustrousFontCatalog.php
    title: LustrousFontCatalog
  - id: cface
    resource: src/Lustrous/Typography/CLandFontFace.php
    title: CLandFontFace
---

# Readers

`OpenTypeFile` opens a font or collection; a collection's face count is refused when the file has no room for it (32 bytes a face).[^file] `PHPFontFace` reads metrics (OS/2 typographic when `USE_TYPO_METRICS`, else `hhea`), the character map, `hmtx` advances, and outlines: `glyf` quadratics (simple and composite) or `CFF ` cubics through `CompactFontFormat` (Type 2 charstrings, subroutines, CID-keyed fonts).[^phpface][^cff] `CLandFontFace` asks ext-typog for the same answers and builds the `Path` from its steps.[^cface]

Variable fonts: coordinates normalised and mapped through `avar` version 1 in 16.16, then `gvar` deltas (with inferred deltas and phantom points), `HVAR` advances and `MVAR` OS/2 and win metrics.[^variations]

Refused by name with a `TypographyException`: `CFF2` outlines, `avar` other than version 1, a face with no `glyf` or `CFF ` table, charstrings past the spec's limits, and any read past a table.

# Catalogs

Scan `typography.directories` and `typography.files`, describe each face (family, weight, slant, width class, `fvar` axes, outline table), and keep the scan in the cache file, keyed by path, size and modified time (`CACHE_VERSION` 3). `match()` follows CSS's font-matching order, `covering()` finds a face for a codepoint in the request's families, then `typography.fallbacks`, then every family in name order, taking each family's nearest face to the request. A face the readers refuse is never offered.[^catalog]

# Shaper

Character to glyph, `hmtx`/`HVAR` advances and `kern` format 0 pairs. No `GSUB`, `GPOS` or complex scripts.

[^file]: OpenTypeFile
[^phpface]: PHPFontFace
[^cff]: CompactFontFormat
[^variations]: FontVariations
[^catalog]: LustrousFontCatalog
[^cface]: CLandFontFace
