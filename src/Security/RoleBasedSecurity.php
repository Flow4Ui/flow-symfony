<?php

namespace Flow\Security;

use Flow\Attributes\State;
use Flow\Contract\SecurityInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class RoleBasedSecurity implements SecurityInterface
{
    private AuthorizationCheckerInterface $authorizationChecker;
    private array $actionRoleMap;

    public function __construct(
        AuthorizationCheckerInterface $authorizationChecker,
        array $actionRoleMap = []
    ) {
        $this->authorizationChecker = $authorizationChecker;
        $this->actionRoleMap = $actionRoleMap;
    }

    /**
     * {@inheritdoc}
     */
    public function isActionAllowed(string $action, State $instanceDefinition, object $instance, array $args): bool
    {
        // Check for specific action-role mappings for this component
        $componentName = $instanceDefinition->name;
        $actionKey = $componentName . '.' . $action;

        // First check if the action has roles defined in its attribute
        $actionDefinition = $instanceDefinition->actions[$action] ?? null;
        if ($actionDefinition !== null && $actionDefinition->roles !== null) {
            $rolesToCheck = $actionDefinition->roles;
        } else {
            // Order of checks if no roles defined in attribute:
            // 1. Specific component.action mapping
            // 2. Component wildcard mapping
            // 3. Global action mapping
            // 4. Default to true if no mappings found
            $rolesToCheck = null;

            if (isset($this->actionRoleMap[$actionKey])) {
                $rolesToCheck = $this->actionRoleMap[$actionKey];
            } elseif (isset($this->actionRoleMap[$componentName . '.*'])) {
                $rolesToCheck = $this->actionRoleMap[$componentName . '.*'];
            } elseif (isset($this->actionRoleMap[$action])) {
                $rolesToCheck = $this->actionRoleMap[$action];
            } elseif (isset($this->actionRoleMap['*'])) {
                $rolesToCheck = $this->actionRoleMap['*'];
            }
        }

        // If no role mapping found, allow access by default
        if ($rolesToCheck === null) {
            return true;
        }

        // Convert string to array for convenience
        if (is_string($rolesToCheck)) {
            $rolesToCheck = [$rolesToCheck];
        }

        // Special case: empty array means deny all
        if (empty($rolesToCheck)) {
            return false;
        }

        // Check if user has any of the required roles
        foreach ($rolesToCheck as $role) {
            if ($this->authorizationChecker->isGranted($role)) {
                return true;
            }
        }

        return false;
    }
}
