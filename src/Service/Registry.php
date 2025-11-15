<?php

declare(strict_types=1);

namespace Flow\Service;

use Flow\Attributes\{Action, Attribute, Component, Property, Router, State, Store, StoreRef};
use Flow\Exception\FlowException;
use ReflectionException;
use function Symfony\Component\String\s;

class Registry
{

    protected array $stateDefinitions = [];
    protected array $statesByName = [];
    protected array $storesByName = [];
    protected array $componentsByName = [];
    /**
     * @var array<string,Component>
     */
    protected array $components = [];
    /**
     * @var array<Router>
     */
    protected array $routes = [];


    public function __construct(
        protected bool        $routerEnabled = false,
        protected string      $routerMode = 'hash',
        protected string|null $routerBase = null,
    )
    {
    }

    /**
     * @throws ReflectionException
     */
    public function getStoreDefinition(string $classNameOrStoreName, $addStore = false): State|null
    {
        $store = $this->stateDefinitions[$classNameOrStoreName] ?? $this->storesByName[$classNameOrStoreName] ?? null;
        if ($store === null && $addStore) {
            $store = new Store(name: '');
            $this->defineState(new \ReflectionClass($classNameOrStoreName), $store);
        }
        return $store;
    }

    /**
     */
    public function defineState(\ReflectionClass $class, ?State $store = null, $isStore = false, ?Component $component = null): void
    {
        if ($store === null) {
            $attributes = $class->getAttributes(State::class, \ReflectionAttribute::IS_INSTANCEOF);
            if (!empty($attributes)) {
                $store = $attributes[0]->newInstance();
                $isStore = $store instanceof Store;
            } else {
                $store = $isStore ? new Store() : new State();
            }
        } else {
            $isStore = $store instanceof Store;
        }


        $storeName = empty($store->name) ? $this->genStateName($class, $store) : $store->name;
        $this->stateDefinitions[$class->getName()] = $store;
        $this->stateDefinitions[$storeName] = $store;
        if ($isStore) {
            $this->storesByName[$storeName] = $store;
        }
        $store->className = $class->getName();
        $store->name = $storeName;

        foreach ($class->getProperties() as $property) {
            $this->computeProperty($property, $store, $component);
            if (!$isStore) {
                $this->computeRef($property, $store);
            }
        }

        foreach ($class->getMethods() as $method) {
            $this->computeAction($method, $store);
            $this->computeGetter($method, $store);
        }

    }

    protected function genStateName(\ReflectionClass $class, \ReflectionAttribute|State $store): string
    {
        return $this->genCammelCaseNameId($class->getShortName());
    }

    protected function genCammelCaseNameId(string $name): string
    {
        return s($name)->camel()->title()->toString();
    }

    /**
     * @param \ReflectionProperty $property
     * @param mixed $store
     * @return void
     */
    private function computeProperty(\ReflectionProperty $property, State $store, ?Component $component = null): void
    {
        $attribute = $property->getAttributes(Property::class, \ReflectionAttribute::IS_INSTANCEOF)[0] ?? null;
        $storeProperty = $attribute?->newInstance();
        if ($storeProperty === null && $store->bindAll && $property->isPublic()) {
            $storeProperty = new Property();
        }
        if ($storeProperty !== null) {
            $storeProperty->reflectionProperty = $property;
            $storeProperty->name = !empty($storeProperty->name) ? $storeProperty->name : $this->genPropertyName($property->getName());
            $store->properties[$storeProperty->name] = $storeProperty;
            if ($component !== null && $storeProperty instanceof Attribute && !in_array($storeProperty->name, $component->props)) {
                $component->props[] = $storeProperty->name;
            }
        }
    }

    private function genPropertyName(string $getName): string
    {
        //todo add more generation name
        return $getName;
    }

    /**
     * @throws ReflectionException
     * @throws FlowException
     */
    private function computeRef(\ReflectionProperty $property, mixed $store): void
    {
        $attribute = $property->getAttributes(StoreRef::class, \ReflectionAttribute::IS_INSTANCEOF)[0] ?? null;
        /**
         * @var $storeProperty StoreRef
         */
        $storeProperty = $attribute?->newInstance();
        if ($storeProperty !== null) {
            $storeProperty->reflectionProperty = $property;
            $storeProperty->name = !empty($storeProperty->name) ? $storeProperty->name : $this->genPropertyName($property->getName());
            $storeName = $storeProperty->store;

            if (!empty($storeName)) {
                $storeName = $this->getStoreDefinition($storeName, class_exists($storeName))?->name;
            }

            if (empty($storeProperty->property) && $property->hasType()) {
                $type = $property->getType();
                $typeName = null;

                // Handle ReflectionNamedType
                if ($type instanceof \ReflectionNamedType) {
                    $typeName = $type->getName();
                }
                // Handle ReflectionUnionType - get the first type
                elseif ($type instanceof \ReflectionUnionType) {
                    $types = $type->getTypes();
                    if (!empty($types) && $types[0] instanceof \ReflectionNamedType) {
                        $typeName = $types[0]->getName();
                    }
                }

                if ($typeName !== null) {
                    $storeName = $this->getStoreDefinition($typeName, true)?->name;
                }
            }

            if (empty($storeName)) {
                throw new FlowException(sprintf('Store reference %s not found', $storeProperty->store));
            }
            $storeProperty->store = $storeName;
            $store->properties[$storeProperty->name] = $storeProperty;
        }
    }

    /**
     * @param \ReflectionMethod $method
     * @param mixed $store
     * @return void
     */
    public function computeAction(\ReflectionMethod $method, State $store): void
    {
        $attribute = $method->getAttributes(Action::class)[0] ?? null;
        /**
         * @var $storeAction Action
         */
        $storeAction = $attribute?->newInstance();
        if ($storeAction !== null) {
            $storeAction->reflectionAction = $method;
            $storeAction->name = !empty($storeAction->name) ? $storeAction->name : $this->genPropertyName($method->getName());
            $store->actions[$storeAction->name] = $storeAction;
        }
    }

    /**
     * @param \ReflectionMethod $method
     * @param mixed $store
     * @return void
     */
    private function computeGetter(\ReflectionMethod $method, State $store): void
    {
        $attribute = $method->getAttributes(Property::class)[0] ?? null;
        /**
         * @var $storeAction Property
         */
        $storeAction = $attribute?->newInstance();
        if ($storeAction !== null) {
            $storeAction->reflectionProperty = $method;
            $storeAction->name = !empty($storeAction->name) ? $storeAction->name : $this->genPropertyName($method->getName());
            $store->actions[$storeAction->name] = $storeAction;
        }
    }

    /**
     * @return Component
     */
    public function getComponentByState(State $state): Component|null
    {
        $component = $this->components[$state->className] ?? null;
        if ($component === null) {
            foreach ($this->components as $definedComponent) {
                if ($definedComponent->stateId === $state->name || $definedComponent->stateId === $state->className) {
                    return $definedComponent;
                }
            }
        }
        return $component;
    }

    /**
     * @throws ReflectionException
     */
    public function getComponentDefinition(string $classNameOrStoreName, $addComponent = false): Component|null
    {
        $store = $this->components[$classNameOrStoreName] ?? $this->componentsByName[$classNameOrStoreName] ?? null;
        if ($store === null && $addComponent) {
            $store = new Component();
            $this->defineComponent(new \ReflectionClass($classNameOrStoreName), $store);
        }
        return $store;
    }

    /**
     * @param \ReflectionClass $class
     * @param Component|null $componentAttribute
     * @return void
     */
    public function defineComponent(\ReflectionClass $class, ?Component $componentAttribute = null)
    {
        if ($componentAttribute === null) {
            $attributes = $class->getAttributes(Component::class, \ReflectionAttribute::IS_INSTANCEOF);
            if (!empty($attributes)) {
                $componentAttribute = $attributes[0]->newInstance();
            } else {
                $componentAttribute = new Component();
            }
        }

        if (empty($componentAttribute->name)) {
            $componentAttribute->name = $this->genCammelCaseNameId($class->getShortName());
        }

        $attributes = $class->getAttributes(Router::class, \ReflectionAttribute::IS_INSTANCEOF);
        if (!empty($attributes)) {
            foreach ($attributes as $attribute) {
                $this->defineComponentRouter($attribute->newInstance(), $componentAttribute);
            }
        }

        if (empty($componentAttribute->stateId)) {
            $attributes = $class->getAttributes(State::class, \ReflectionAttribute::IS_INSTANCEOF);
            if (!empty($attributes)) {
                $state = $attributes[0]->newInstance();
            } else {
                $state = new State();
            }
            $state->name ??= $componentAttribute->name;
            $this->defineState(class: $class, store: $state, component: $componentAttribute);
            $componentAttribute->stateId = $class->getName();
        }

        $this->components[$class->name] = $this->componentsByName[$componentAttribute->name] = $componentAttribute;
    }

    private function defineComponentRouter(Router $routerAttribute, Component $componentAttribute): void
    {
        $this->routes[] = $routerAttribute;
        $routerAttribute->component = $componentAttribute->name;
    }

    /**
     * @throws ReflectionException
     */
    public function getStateDefinition(string $classNameOrStoreName, $addState = false): State|null
    {
        $store = $this->stateDefinitions[$classNameOrStoreName] ?? $this->statesByName[$classNameOrStoreName] ?? null;
        if ($store === null && $addState) {
            $store = new State(name: '');
            $this->defineState(new \ReflectionClass($classNameOrStoreName), $store);
        }
        return $store;
    }

    public function defineStateByClassName($className): void
    {
        if (class_exists($className)) {
            $this->defineState(new \ReflectionClass($className));
        }
    }

    public function defineComponentByClassName($className): void
    {
        if (class_exists($className)) {
            $this->defineComponent(new \ReflectionClass($className));
        }
    }

    /**
     * @return Router[]
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }

    public function isRouterEnabled(): bool
    {
        return $this->routerEnabled;
    }

    public function getRouterMode(): string
    {
        return $this->routerMode;
    }

    public function getRouterBase(): ?string
    {
        return $this->routerBase;
    }

}