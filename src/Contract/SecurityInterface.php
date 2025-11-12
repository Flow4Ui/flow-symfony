<?php

namespace Flow\Contract;

use Flow\Attributes\State;

interface SecurityInterface
{
    /**
     * Check if the current user is allowed to execute the specified action
     * 
     * Implementations should check:
     * 1. Role requirements in the Action attribute (if defined)
     * 2. Component-specific role mappings from configuration
     * 3. Global action role mappings from configuration
     *
     * @param string $action The action name
     * @param State $instanceDefinition The state definition with actions that may contain role requirements
     * @param object $instance The state instance
     * @param array $args The action arguments
     * @return bool True if the user is allowed to execute the action
     */
    public function isActionAllowed(string $action, State $instanceDefinition, object $instance, array $args): bool;
}
