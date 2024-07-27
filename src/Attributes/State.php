<?php

namespace Flow\Attributes;

#[\Attribute(\Attribute::TARGET_CLASS)]
class State
{

    // todo: add normalizerDenormalizeAttributes to property and action
    /**
     * @var array<Property|StoreRef>
     */
    public array $properties = [];

    /**
     * @var array<Action>
     */
    public array $actions = [];

    public string|null $className = null;

    public function __construct(
        public string|null       $name = null,
        readonly public bool     $bindAll = false,
        readonly public int|null $refresh = null,
        readonly public array    $normalizeAttributes = [],
        readonly public array    $denormalizeAttributes = [],
    )
    {
    }
}