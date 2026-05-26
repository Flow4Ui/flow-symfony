<?php

namespace Flow\Routing;

use Flow\Attributes\Router;

final class RouteDefinition implements \JsonSerializable
{
    /**
     * @param RouteDefinition[] $children
     */
    public function __construct(
        public string      $path,
        public string|null $name = null,
        public string|null $component = null,
        public bool|array  $props = true,
        public array|null  $meta = null,
        public array       $children = [],
    )
    {
    }

    public static function fromRouter(Router $router, ?string $defaultComponent = null): self
    {
        $component = $router->component ?? $defaultComponent;
        $children = [];

        foreach ($router->children as $child) {
            if (!$child instanceof Router) {
                throw new \InvalidArgumentException('Router children must be instances of Flow\Attributes\Router.');
            }

            $children[] = self::fromRouter($child, $component);
        }

        return new self(
            path: $router->path,
            name: $router->name,
            component: $component,
            props: $router->props,
            meta: $router->meta,
            children: $children,
        );
    }

    public function jsonSerialize(): array
    {
        $route = [
            'path' => $this->path,
            'name' => $this->name,
            'component' => $this->component,
            'props' => $this->props,
        ];

        if ($this->meta !== null) {
            $route['meta'] = $this->meta;
        }

        if ($this->children !== []) {
            $route['children'] = array_map(
                static fn(RouteDefinition $child): array => $child->jsonSerialize(),
                $this->children,
            );
        }

        return $route;
    }
}
