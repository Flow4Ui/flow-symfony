<?php

namespace Flow\Event;

use Flow\Attributes\State;
use Flow\Service\Manager;
use Symfony\Component\HttpFoundation\Request;

class BeforeActionInvokeEvent extends ManagerEvent
{
    private string $action;
    private State $instanceDefinition;
    private object $instance;
    private array $args;
    private bool $canProceed = true;

    public function __construct(Manager $manager, Request $request, string $action, State $instanceDefinition, object $instance, array $args)
    {
        parent::__construct($manager, $request);
        $this->action = $action;
        $this->instanceDefinition = $instanceDefinition;
        $this->instance = $instance;
        $this->args = $args;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getInstanceDefinition(): State
    {
        return $this->instanceDefinition;
    }

    public function getInstance(): object
    {
        return $this->instance;
    }

    public function getArgs(): array
    {
        return $this->args;
    }

    public function setArgs(array $args): self
    {
        $this->args = $args;
        return $this;
    }

    public function canProceed(): bool
    {
        return $this->canProceed;
    }

    public function preventExecution(): self
    {
        $this->canProceed = false;
        return $this;
    }
}
