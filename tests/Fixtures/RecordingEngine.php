<?php

declare(strict_types=1);

use Surface\Contracts\Rendering\Framebuffers\FormatSpec;
use Surface\Contracts\Rendering\Painting\PaintTarget;
use VelvetGE\Contracts\Framebuffers\CompositingFramebuffer;
use Surface\Rendering\Painters\Painter;

/** A rendering engine that draws nothing: it keeps what it was handed to execute, so tests can read the recorded commands. */
final class RecordingEngine extends Painter
{
    /** @var list<list<array<int, mixed>>> One command list per execution. */
    public array $executed = [];

    private CompositingFramebuffer&PaintTarget $framebuffer;

    /**
     * @param  int|(CompositingFramebuffer&PaintTarget)  $framebuffer  A surface size, or the framebuffer the engine draws over.
     * @param  \Surface\Contracts\Rendering\Recording\Recorder|null  $recorder  Null is the recorder under test.
     */
    public function __construct(int|(CompositingFramebuffer&PaintTarget) $framebuffer = 100, int $height = 80, ?\Surface\Contracts\Rendering\Recording\Recorder $recorder = null)
    {
        $this->framebuffer = ! is_int($framebuffer)
            ? $framebuffer
            : nativeFull(FormatSpec::rgba8(), $framebuffer, $height);
        $this->recordWith($recorder ?? recorderUnderTest());
    }

    public function name(): string
    {
        return 'recording';
    }

    public function framebuffer(): CompositingFramebuffer&PaintTarget
    {
        return $this->framebuffer;
    }

    protected function execute(array $commands): void
    {
        $this->executed[] = $commands;
    }

    /** @return list<array<int, mixed>> The commands of one frame drawn by $draw. */
    public function commandsOf(callable $draw): array
    {
        $this->frame($draw);

        return $this->executed[array_key_last($this->executed)];
    }
}
