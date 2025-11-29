<?php

namespace Flow\Attributes;

#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
class Router
{
    public function __construct(
        public string      $path,
        public string|null $name = null,
        public string|null $component = null,
        public array|null  $components = null,
        public bool        $props = true,
    )
    {
    }
}
