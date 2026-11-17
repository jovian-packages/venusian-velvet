# Security Policy

## Supported versions

Venusian is pre-1.0. No 0.x release receives security fixes or advisories; fixes land in the
next release line. Security support starts with 1.0.

| Version | Security fixes |
|---------|----------------|
| < 1.0   | No             |

## Reporting a vulnerability

Please don't open a public issue for a security problem.

Report it privately through GitHub: the **Report a vulnerability** button on this repository's
**Security** tab. If that isn't available, email **info@projectsaturnstudios.com**.

Include what you found, the affected version, and steps to reproduce. Reports are read and
weighed for the release line in development; before 1.0 there is no response-time commitment.

## Security model

- **Font files are parsed by this package**: in PHP by the `lustrous-php` readers, and in C by ext-typog for `lustrous-c`. Every read is bounded by the file's length; a table, offset, count or charstring that runs past its data is refused with a `TypographyException` naming the font. Collection face counts are capped by the room the file has for them, so a forged header cannot make a scan open billions of faces.
- **CFF charstrings are interpreted** with the limits the spec sets: an operand stack of 48, subroutine nesting of 10. Anything past them, an unknown operator, `random` and division by zero are refused.
- **Font files are opened read-only**, from paths in `typography.directories`, `typography.files` or a `FontSource`. Paths come from the application or its catalog, never from the text being set.
- **One file is written**: the catalog's scan cache, `storage/framework/cache/fonts.json` or the file `typography.cache` names. It holds paths, names and metrics of fonts found, and is reread only when a font's size or modified time changes.
- **Pixels.** `lustrous-c` parts write into frames in C memory through their extensions; sizes and strides are checked by those extensions before any write.
