<?php

namespace Flow\Contract;

use Symfony\Component\HttpFoundation\Request;

interface HasPreAction
{
    public function preAction(Request $request): void;
}