<?php

namespace Flow\Contract;

use Symfony\Component\HttpFoundation\Request;

interface HasPreUpdateState
{
    /**
     * @param Request $request
     * @param array $state
     * @return array
     */
    public function preUpdateState(Request $request, array $state): array;
}