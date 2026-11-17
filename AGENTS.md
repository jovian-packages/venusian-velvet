# Agent guidance — jovian/venusian-velvet

1. **Read [`.okf/index.md`](.okf/index.md) first.** Open only the concepts the change touches.
2. **The contracts are Topside's** (`Surface\Contracts\Rendering\…`, `Surface\Contracts\Typography\…`, `VelvetGE\Contracts\…`). Lustrous implements them; it does not change them. Ask before assuming a change.
3. **Two drivers a part, one behaviour.** `lustrous-php` and `lustrous-c` give the same answers for the same calls; a test runs every behaviour against both (`tests/Datasets`). `auto` chooses between those two and nothing else, and a `lustrous-c` driver is registered only where its extension is loaded.
4. **Extensions:** ext-fb, ext-rasterize, ext-paint, ext-raycast, ext-typog, or ext-velvet in place of the first three. Never another jovian package, and no module depends on this one. No FFI.
5. **Velvet is not the reference.** Other engines and modules are never made to match Lustrous's bytes or speed, and no test checks them against it.
6. **Pixels stay where the frame keeps them.** C parts work on C memory and hand no pixels back to PHP.
7. **Typography.** Font readers refuse by name what they do not read (CFF2, `avar` 2, faces with no `glyf` or `CFF ` outlines), and the catalogs never offer a face the readers refuse. Test fonts are written by `tests/Fixtures/TinyFont.php` and `TinyCff.php`, to the OpenType spec, so every expected value sits in the test.
8. **Tests** name the behaviour they protect and fail when that logic is broken. Run them as [`.okf/runbooks/testing.md`](.okf/runbooks/testing.md) says: in a workbench beside venusian/topside, never the repo root. The whole suite passes; a failure is new. Tests that make `VelvetDevice` or use Velvet's managers take a container from `tests/Support/VelvetApp.php`: config, Velvet's and Topside's managers, and this provider's `boot()`.
9. **Leave nothing behind.** No `vendor/`, lock file or scratch file stays in the repo after a run; `.gitignore` hiding it does not make it gone.
10. **House files travel with the code.** A change that alters behaviour updates `README.md`, the `.okf` concept it touches (bump `generated.at`), `.okf/log.md`, and `SECURITY.md` when what the package reads or writes changes. `.github/workflows/tests.yml` runs the suite on GitHub.
11. **Version** 0.11, the framework's line.
