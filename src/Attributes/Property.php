<?php

namespace Flow\Attributes;

use Flow\Enum\Direction;

#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]
class Property
{
    public \ReflectionProperty|\ReflectionMethod|null $reflectionProperty = null;

    public function __construct(
        public string|null        $name = null,
        readonly public Direction $direction = Direction::Booth,
    )
    {
    }
}