<?php

namespace App\Tests\Service;

use Flow\Attributes\Component;
use Flow\Attributes\Router;
use Flow\Routing\RouteDefinition;
use Flow\Service\Registry;
use PHPUnit\Framework\TestCase;

final class RegistryRouterTest extends TestCase
{
    public function testDefineComponentConvertsRouterAttributesToRouteDefinitions(): void
    {
        $registry = new Registry();

        $registry->defineComponent(new \ReflectionClass(ProductCrudComponent::class));

        $routes = $registry->getRoutes();

        self::assertCount(1, $routes);
        self::assertContainsOnlyInstancesOf(RouteDefinition::class, $routes);
        self::assertSame([
            'path' => '/product-crud',
            'name' => 'admin.catalog.product',
            'component' => 'ProductCrud',
            'props' => ['mode' => 'index'],
            'meta' => ['section' => 'catalog'],
            'children' => [
                [
                    'path' => '',
                    'name' => 'admin.catalog.product.index',
                    'component' => 'ProductCrud',
                    'props' => true,
                ],
                [
                    'path' => 'create',
                    'name' => 'admin.catalog.product.create',
                    'component' => 'ProductCreatePage',
                    'props' => true,
                    'meta' => ['mode' => 'create'],
                ],
            ],
        ], $routes[0]->jsonSerialize());
    }

    public function testRouterParentByRouteNameAttachesRouteAsChild(): void
    {
        $registry = new Registry();

        $registry->defineComponent(new \ReflectionClass(CatalogParentComponent::class));
        $registry->defineComponent(new \ReflectionClass(ProductIndexByRouteNameComponent::class));

        self::assertSame([
            [
                'path' => '/catalog',
                'name' => 'admin.catalog',
                'component' => 'CatalogParent',
                'props' => true,
                'children' => [
                    [
                        'path' => 'product-crud',
                        'name' => 'admin.catalog.product.index',
                        'component' => 'ProductIndexByRouteName',
                        'props' => ['mode' => 'index'],
                    ],
                ],
            ],
        ], array_map(static fn(RouteDefinition $route): array => $route->jsonSerialize(), $registry->getRoutes()));
    }

    public function testRouterParentByComponentClassAttachesRouteAsChild(): void
    {
        $registry = new Registry();

        $registry->defineComponent(new \ReflectionClass(CatalogParentComponent::class));
        $registry->defineComponent(new \ReflectionClass(ProductIndexByClassComponent::class));

        self::assertSame([
            [
                'path' => '/catalog',
                'name' => 'admin.catalog',
                'component' => 'CatalogParent',
                'props' => true,
                'children' => [
                    [
                        'path' => 'product-crud',
                        'name' => 'admin.catalog.product.class_index',
                        'component' => 'ProductIndexByClass',
                        'props' => ['mode' => 'index'],
                    ],
                ],
            ],
        ], array_map(static fn(RouteDefinition $route): array => $route->jsonSerialize(), $registry->getRoutes()));
    }

    public function testRouterParentCanBeDiscoveredBeforeParentRoute(): void
    {
        $registry = new Registry();

        $registry->defineComponent(new \ReflectionClass(ProductIndexByRouteNameComponent::class));
        $registry->defineComponent(new \ReflectionClass(CatalogParentComponent::class));

        $routes = $registry->getRoutes();

        self::assertCount(1, $routes);
        self::assertSame('admin.catalog', $routes[0]->name);
        self::assertSame('admin.catalog.product.index', $routes[0]->children[0]->name);
    }

    public function testUnknownRouteNameParentThrowsException(): void
    {
        $registry = new Registry();

        $registry->defineComponent(new \ReflectionClass(UnknownRouteParentComponent::class));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Router parent route 'admin.missing' was not found.");

        $registry->getRoutes();
    }

    public function testParentClassWithoutRoutesThrowsException(): void
    {
        $registry = new Registry();

        $registry->defineComponent(new \ReflectionClass(ParentWithoutRouteComponent::class));
        $registry->defineComponent(new \ReflectionClass(ProductIndexWithParentWithoutRouteComponent::class));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf("Router parent class '%s' does not define any routes.", ParentWithoutRouteComponent::class));

        $registry->getRoutes();
    }

    public function testParentClassWithMultipleRoutesThrowsException(): void
    {
        $registry = new Registry();

        $registry->defineComponent(new \ReflectionClass(MultiRouteParentComponent::class));
        $registry->defineComponent(new \ReflectionClass(ProductIndexWithMultiRouteParentComponent::class));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf("Router parent class '%s' defines multiple routes; use a route name as parent instead.", MultiRouteParentComponent::class));

        $registry->getRoutes();
    }
}

#[Component(name: 'ProductCrud')]
#[Router(
    path: '/product-crud',
    name: 'admin.catalog.product',
    props: ['mode' => 'index'],
    meta: ['section' => 'catalog'],
    children: [
        new Router(path: '', name: 'admin.catalog.product.index'),
        new Router(
            path: 'create',
            name: 'admin.catalog.product.create',
            component: 'ProductCreatePage',
            meta: ['mode' => 'create'],
        ),
    ],
)]
final class ProductCrudComponent
{
}

#[Component(name: 'CatalogParent')]
#[Router(path: '/catalog', name: 'admin.catalog')]
final class CatalogParentComponent
{
}

#[Component(name: 'ProductIndexByRouteName')]
#[Router(
    parent: 'admin.catalog',
    path: 'product-crud',
    name: 'admin.catalog.product.index',
    props: ['mode' => 'index'],
)]
final class ProductIndexByRouteNameComponent
{
}

#[Component(name: 'ProductIndexByClass')]
#[Router(
    parent: CatalogParentComponent::class,
    path: 'product-crud',
    name: 'admin.catalog.product.class_index',
    props: ['mode' => 'index'],
)]
final class ProductIndexByClassComponent
{
}

#[Component(name: 'UnknownRouteParent')]
#[Router(
    parent: 'admin.missing',
    path: 'product-crud',
    name: 'admin.catalog.product.missing_parent',
)]
final class UnknownRouteParentComponent
{
}

#[Component(name: 'ParentWithoutRoute')]
final class ParentWithoutRouteComponent
{
}

#[Component(name: 'ProductIndexWithParentWithoutRoute')]
#[Router(
    parent: ParentWithoutRouteComponent::class,
    path: 'product-crud',
    name: 'admin.catalog.product.parent_without_route',
)]
final class ProductIndexWithParentWithoutRouteComponent
{
}

#[Component(name: 'MultiRouteParent')]
#[Router(path: '/catalog', name: 'admin.catalog.multi_one')]
#[Router(path: '/catalog-alt', name: 'admin.catalog.multi_two')]
final class MultiRouteParentComponent
{
}

#[Component(name: 'ProductIndexWithMultiRouteParent')]
#[Router(
    parent: MultiRouteParentComponent::class,
    path: 'product-crud',
    name: 'admin.catalog.product.multi_parent',
)]
final class ProductIndexWithMultiRouteParentComponent
{
}
