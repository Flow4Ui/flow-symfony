<?php

namespace Flow\Event;

use Flow\Service\Manager;
use Symfony\Contracts\EventDispatcher\Event;

class BeforeFlowOptionsCompileEvent extends Event
{
    public const NAME = 'flow.before_flow_options_compile';

    public function __construct(
        private readonly Manager $manager,
        private array $options,
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
}
