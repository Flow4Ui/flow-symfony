<?php

namespace Flow\Contract;

use Symfony\Component\HttpFoundation\Request;

interface HasInitState
{
    public function initState(Request $request): void;
}