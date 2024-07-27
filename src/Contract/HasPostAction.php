<?php

namespace Flow\Contract;

use Symfony\Component\HttpFoundation\Request;

interface HasPostAction
{
    public function postAction(Request $request): void;
}