<?php

namespace Flow\Contract;

use Symfony\Component\HttpFoundation\Request;

interface HasUpdateState
{
    public function updateState(Request $request): void;
}