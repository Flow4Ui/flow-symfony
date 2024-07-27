<?php

namespace Flow\Attributes;

#[\Attribute(\Attribute::TARGET_CLASS)]
class Component
{
    /**
     * @param string|null $name
     * @param array<string> $props
     * @param string|null $stateId
     * @param string|null $template
     * @param string|null $templatePath
     * @param bool $client
     */
    public function __construct(
        public string|null $name = null,
        public array       $props = [],
        public string|null $stateId = null,
        public string|null $template = null,
        public string|null $templatePath = null,
        public bool        $client = false,
    )
    {
    }
}