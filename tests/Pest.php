<?php

declare(strict_types=1);

/*
 * Lustrous's suite. VelvetGE's own fixtures (the fake stores, managers, inputs, marchers) come from
 * topside's tests, which this package loads through autoload-dev; what is here is what needs Lustrous.
 */
$topside = dirname(__DIR__).'/../../venusian/topside/tests';

require_once $topside.'/Fixtures/FakeFramebuffers.php';
require_once $topside.'/Fixtures/FakeManagers.php';
require_once $topside.'/Fixtures/FakeInput.php';
require_once $topside.'/Fixtures/RaycastHelpers.php';
require_once $topside.'/Fixtures/FilterFixtures.php';
require_once __DIR__.'/Fixtures/LustrousFramebuffers.php';
require_once __DIR__.'/Fixtures/LustrousManagers.php';
require_once __DIR__.'/Fixtures/FakeScanners.php';
require_once __DIR__.'/Fixtures/FakeMarchers.php';
require_once __DIR__.'/Datasets/RaycastDrivers.php';
require_once __DIR__.'/Datasets/TypographyDrivers.php';
require_once __DIR__.'/Fixtures/RecordingEngine.php';
require_once __DIR__.'/Support/WatchedFrame.php';
require_once __DIR__.'/Support/ModuleTicketChecks.php';

/** A full framebuffer with its bytes in PHP. */
function nativeFull(\Surface\Contracts\Rendering\Framebuffers\FormatSpec $format, int $width, int $height): \Jovian\Engines\Velvet\Lustrous\Framebuffers\FullFramebuffer
{
    return (new \Jovian\Engines\Velvet\Lustrous\Framebuffers\PHPLandFramebufferDriver())->full($format, $width, $height);
}

// Tests run without the container, so the stroking manager is not there: the suite strokes with
// Lustrous's PHP stroker, or with its C stroker under TOPSIDE_STROKER=ext.
\Surface\Rendering\Stroker::use(getenv('TOPSIDE_STROKER') === 'ext'
    ? new \Jovian\Engines\Velvet\Lustrous\Stroking\CLandStroker()
    : new \Jovian\Engines\Velvet\Lustrous\Stroking\PHPStroker());
