<?php

namespace Flow\Event;

use Flow\Service\Manager;
use Symfony\Component\HttpFoundation\Request;

class PostActionEvent extends ManagerEvent
{
    private array $instances;

    public function __construct(Manager $manager, Request $request, array $instances)
    {
        parent::__construct($manager, $request);
        $this->instances = $instances;
    }

    public function getInstances(): array
    {
        return $this->instances;
    }
}
