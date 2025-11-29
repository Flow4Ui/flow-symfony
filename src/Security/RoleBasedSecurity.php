<?php

namespace Flow\Security;

use Flow\Attributes\{Component, State};
use Flow\Contract\SecurityInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

class RoleBasedSecurity implements SecurityInterface
{
    private AuthorizationCheckerInterface $authorizationChecker;
    private array $actionRoleMap;
    private array $componentRoleMap;
    private bool $componentSecurityEnabled;

    public function __construct(
        AuthorizationCheckerInterface $authorizationChecker,
        array                        $actionRoleMap = [],
        array                        $componentRoleMap = [],
        bool                         $componentSecurityEnabled = false,
    ) {
        $this->authorizationChecker = $authorizationChecker;
        $this->actionRoleMap = $actionRoleMap;
        $this->componentRoleMap = $componentRoleMap;
        $this->componentSecurityEnabled = $componentSecurityEnabled;
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

        return $this->checkRoles($rolesToCheck);
    }

    public function isComponentAllowed(Component $component): bool
    {
        if (!$this->componentSecurityEnabled) {
            return true;
        }

        $rolesToCheck = $component->roles;

        if ($rolesToCheck === null) {
            if (isset($this->componentRoleMap[$component->name])) {
                $rolesToCheck = $this->componentRoleMap[$component->name];
            } elseif (isset($this->componentRoleMap['*'])) {
                $rolesToCheck = $this->componentRoleMap['*'];
            }
        }

        return $this->checkRoles($rolesToCheck);
    }

    /**
     * @param array|string|null $rolesToCheck
     */
    private function checkRoles(array|string|null $rolesToCheck): bool
    {
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
