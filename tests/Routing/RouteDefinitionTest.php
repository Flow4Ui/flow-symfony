<?php

namespace App\Tests\Routing;

use Flow\Attributes\Router;
use Flow\Routing\RouteDefinition;
use PHPUnit\Framework\TestCase;

final class RouteDefinitionTest extends TestCase
{
    public function testSerializesPropsMetaAndChildren(): void
    {
        $definition = RouteDefinition::fromRouter(new Router(
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
                    props: false,
                    meta: ['mode' => 'create'],
                ),
            ],
        ), 'ProductIndexPage');

        self::assertSame([
            'path' => '/product-crud',
            'name' => 'admin.catalog.product',
            'component' => 'ProductIndexPage',
            'props' => ['mode' => 'index'],
            'meta' => ['section' => 'catalog'],
            'children' => [
                [
                    'path' => '',
                    'name' => 'admin.catalog.product.index',
                    'component' => 'ProductIndexPage',
                    'props' => true,
                ],
                [
                    'path' => 'create',
                    'name' => 'admin.catalog.product.create',
                    'component' => 'ProductCreatePage',
                    'props' => false,
                    'meta' => ['mode' => 'create'],
                ],
            ],
        ], $definition->jsonSerialize());
    }

    public function testRejectsInvalidChildRouteDefinitions(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Router children must be instances of Flow\Attributes\Router.');

        RouteDefinition::fromRouter(new Router(
            path: '/product-crud',
            children: [
                ['path' => 'create'],
            ],
        ), 'ProductIndexPage');
    }
}
