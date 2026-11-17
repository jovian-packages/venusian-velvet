<?php

namespace Jovian\Engines\Velvet\Lustrous\Framebuffers;


use Jovian\Engines\Velvet\Lustrous\Framebuffers\StoreFramebuffer;

/** One whole frame. Keeps its contents after a present; its damage is always the whole surface. */
class FullFramebuffer extends StoreFramebuffer {}
