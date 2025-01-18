<?php

namespace Flow\Component;

use Flow\Contract\HasCallbacks;
use Flow\Contract\HasClientSideMethods;
use Flow\Contract\Methods;

abstract class AbstractComponent implements HasClientSideMethods, HasCallbacks
{

    private $callbacks = [];
    private Methods $methods;

    public function addCallback(string $fn, ...$args): self
    {
        $this->callbacks[] = ['fn' => $fn, 'args' => $args];
        return $this;
    }

    /**
     * Returns callbacks
     *
     * @return array<array{fn: string, args: array}>
     */
    public function getCallbacks(): array
    {
        return $this->callbacks;
    }

    public function getClientSideMethods(): Methods
    {
        $this->methods ??= new Methods();
        return $this->methods;
    }

}