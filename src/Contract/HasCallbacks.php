<?php

namespace Flow\Contract;

interface HasCallbacks
{
    /**
     * Returns callbacks
     *
     * @return array<array{fn: string, args: array}>
     */
    public function getCallbacks(): array;

}