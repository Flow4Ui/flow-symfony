<?php

namespace Flow\Attributes;

use Flow\Enum\StateUpdateType;

#[\Attribute(\Attribute::TARGET_METHOD)]
class Action
{
    public \ReflectionMethod|null $reflectionAction = null;

    public function __construct(
        public string|null              $name = null,
        readonly public array|null      $output = null,
        readonly public array|null      $input = null,
        readonly public StateUpdateType $updateType = StateUpdateType::REPLACE,
        readonly public array|null $roles = null,
    )
    {
    }

}