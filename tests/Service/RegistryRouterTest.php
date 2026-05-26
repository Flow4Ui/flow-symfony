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
