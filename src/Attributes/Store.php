<?php

namespace Flow\Attributes;

#[\Attribute(\Attribute::TARGET_CLASS)]
class Store extends State
{
    public function __construct(
        string|null $name = null,
        bool        $bindAll = false,
        bool        $autoRefresh = false,
        int|null    $refresh = null,
        array       $normalizeAttributes = [],
        array       $denormalizeAttributes = [],
    )
    {
        parent::__construct($name, $bindAll, $refresh, $normalizeAttributes, $denormalizeAttributes);
    }
}