<?php

namespace Flow\Event;

use Flow\Service\Manager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\EventDispatcher\Event;

abstract class ManagerEvent extends Event
{
    protected Manager $manager;
    protected Request $request;

    public function __construct(Manager $manager, Request $request)
    {
        $this->manager = $manager;
        $this->request = $request;
    }

    public function getManager(): Manager
    {
        return $this->manager;
    }

    public function getRequest(): Request
    {
        return $this->request;
    }
}
