<?php

namespace Flow\Event;

use Flow\Service\Manager;
use Symfony\Contracts\EventDispatcher\Event;

class AfterFlowOptionsCompileEvent extends Event
{
    public const NAME = 'flow.after_flow_options_compile';

    public function __construct(
        private readonly Manager $manager,
        private array $options,
        private array $flowOptions,
    ) {
    }

    public function getManager(): Manager
    {
        return $this->manager;
    }

    public function getOptions(): array
    {
        return $this->options;
    }

    public function setOptions(array $options): self
    {
        $this->options = $options;

        return $this;
    }

    public function getFlowOptions(): array
    {
        return $this->flowOptions;
    }

    public function setFlowOptions(array $flowOptions): self
    {
        $this->flowOptions = $flowOptions;

        return $this;
    }
}
