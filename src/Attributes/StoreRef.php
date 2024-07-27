<?php

namespace Flow\Attributes;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
class StoreRef
{
    public \ReflectionProperty|null $reflectionProperty = null;

    public function __construct(
        public string|null $store = null,
        public string|null $property = null,
        public string|null $name = null,
    )
    {
    }
}