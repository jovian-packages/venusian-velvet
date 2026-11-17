---
type: Runbook
title: Testing
description: Pest suite in a workbench beside venusian/topside; both drivers per behaviour; known failures; Mac, Pi, gaming PC and CI.
resource: tests/
tags: [lustrous, pest]
status: draft
generated: { by: claude-opus/5.5, at: 2026-11-17T00:00:00-05:00 }
sources:
  - id: pest
    resource: tests/Pest.php
    title: tests/Pest.php
  - id: datasets
    resource: tests/Datasets/TypographyDrivers.php
    title: TypographyDrivers
  - id: app
    resource: tests/Support/VelvetApp.php
    title: VelvetApp
  - id: ci
    resource: .github/workflows/tests.yml
    title: tests.yml
---
# Steps
* The suite loads topside's test fixtures from `../../venusian/topside/tests`, so the package must sit at `<root>/jovian/venusian-velvet` with topside at `<root>/venusian/topside`.[^pest]
* Workbench, never the repo root: copy the package without `vendor/`, `.git` and lock files to `<scratch>/jovian/venusian-velvet`, link or clone topside to `<scratch>/venusian/topside`, then `composer config repositories.topside '{"type": "path", "url": "../../venusian/topside"}'` and `php84 /opt/homebrew/bin/composer update`.
* Mac: `XDEBUG_MODE=off php84 vendor/bin/pest`, then `zhp`. 2026-11-17: 3255 passed, 5 skipped, 0 failed, 46 s.
* Both drivers: datasets yield `lustrous-php` always and `lustrous-c` where its extension is loaded, so a PHP without the extensions runs half the cases.[^datasets] Strokers use `TOPSIDE_STROKER=ext` for the C one.
* The engine and its managers as an app has them: `velvetApp($config, $modules)` (`tests/Support/VelvetApp.php`) makes a container with the config given, Velvet's and Topside's managers and Lustrous's provider booted onto them, and defines `app()` and `config()` as venusian/framework does. `VelvetDevice` reaches its modules through them.[^app]
* Pi and gaming PC: ship fresh clones of HEAD and topside in the same layout, packed with `COPYFILE_DISABLE=1 tar --no-mac-metadata --no-xattrs`; build the extensions there from current source.
* After: delete the workbench. The repo keeps no `vendor/` and no lock file.
* CI: `tests.yml` runs PHP 8.4 and 8.5, each with no extensions (`lustrous-php`) and with ext-fb, -rasterize, -paint, -raycast and -typog built from `php-io-extensions` branch `0.11.x` (`lustrous-c`); topside cloned to `../../venusian/topside`.[^ci]
* A test names the behaviour it protects. Break that logic once before trusting the test; on built C, check an unbroken baseline passes in the same harness first and rebuild from clean for every break.

[^pest]: tests/Pest.php
[^datasets]: TypographyDrivers
[^app]: VelvetApp
[^ci]: tests.yml
