<?php

namespace Flow\Attributes;

use Flow\Enum\Direction;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
class Attribute extends Property
{
    public function __construct(?string $name = null, Direction $direction = Direction::Server)
    {
        parent::__construct($name, $direction);
    }
}