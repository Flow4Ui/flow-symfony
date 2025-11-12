<?php

namespace Flow\Event;

use Flow\Attributes\State;
use Flow\Service\Manager;
use Symfony\Component\HttpFoundation\Request;

class AfterActionInvokeEvent extends ManagerEvent
{
    private string $action;
    private State $instanceDefinition;
    private object $instance;
    private array $args;
    private mixed $result;

    public function __construct(Manager $manager, Request $request, string $action, State $instanceDefinition, object $instance, array $args, mixed $result)
    {
        parent::__construct($manager, $request);
        $this->action = $action;
        $this->instanceDefinition = $instanceDefinition;
        $this->instance = $instance;
        $this->args = $args;
        $this->result = $result;
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

    public function getResult(): mixed
    {
        return $this->result;
    }

    public function setResult(mixed $result): self
    {
        $this->result = $result;
        return $this;
    }
}
