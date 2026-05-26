<?php

namespace App\Tests\Routing;

use Flow\Routing\FlowRouter;
use Flow\Routing\RouteDefinition;
use Flow\Service\Registry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Exception\MissingMandatoryParametersException;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;

final class FlowRouterTest extends TestCase
{
    public function testGenerateResolvesRelativeChildRoutePath(): void
    {
        $router = $this->createFlowRouter([
            new RouteDefinition(
                path: '/product-crud',
                name: 'admin.catalog.product',
                component: 'ProductPage',
                children: [
                    new RouteDefinition(path: 'create', name: 'admin.catalog.product.create', component: 'ProductCreatePage'),
                ],
            ),
        ]);

        self::assertSame('/product-crud/create', $router->generate('admin.catalog.product.create'));
    }

    public function testGenerateKeepsAbsoluteChildRoutePath(): void
    {
        $router = $this->createFlowRouter([
            new RouteDefinition(
                path: '/product-crud',
                name: 'admin.catalog.product',
                component: 'ProductPage',
                children: [
                    new RouteDefinition(path: '/catalog/settings', name: 'admin.catalog.settings', component: 'SettingsPage'),
                ],
            ),
        ]);

        self::assertSame('/catalog/settings', $router->generate('admin.catalog.settings'));
    }

    public function testGeneratePreservesParametersQueryAndFragmentForChildRoute(): void
    {
        $router = $this->createFlowRouter([
            new RouteDefinition(
                path: '/product-crud/{tenant}',
                name: 'admin.catalog.product',
                component: 'ProductPage',
                children: [
                    new RouteDefinition(path: ':id/edit', name: 'admin.catalog.product.edit', component: 'ProductEditPage'),
                ],
            ),
        ]);

        self::assertSame(
            '/product-crud/acme/42/edit?tab=details#pricing',
            $router->generate('admin.catalog.product.edit', [
                'tenant' => 'acme',
                'id' => 42,
                'tab' => 'details',
                '_fragment' => 'pricing',
            ]),
        );
    }

    public function testGenerateReportsMissingParametersForChildRoute(): void
    {
        $router = $this->createFlowRouter([
            new RouteDefinition(
                path: '/product-crud/{tenant}',
                name: 'admin.catalog.product',
                component: 'ProductPage',
                children: [
                    new RouteDefinition(path: ':id/edit', name: 'admin.catalog.product.edit', component: 'ProductEditPage'),
                ],
            ),
        ]);

        $this->expectException(MissingMandatoryParametersException::class);

        $router->generate('admin.catalog.product.edit', ['tenant' => 'acme']);
    }

    /**
     * @param RouteDefinition[] $routes
     */
    private function createFlowRouter(array $routes): FlowRouter
    {
        $inner = new class implements RouterInterface {
            private RequestContext $context;

            public function __construct()
            {
                $this->context = new RequestContext();
            }

            public function getRouteCollection(): RouteCollection
            {
                return new RouteCollection();
            }

            public function setContext(RequestContext $context): void
            {
                $this->context = $context;
            }

            public function getContext(): RequestContext
            {
                return $this->context;
            }

            public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
            {
                throw new RouteNotFoundException();
            }

            public function match(string $pathinfo): array
            {
                return [];
            }
        };

        $registry = $this->createMock(Registry::class);
        $registry->method('getRoutes')->willReturn($routes);
        $registry->method('getRouterMode')->willReturn('history');
        $registry->method('getRouterBase')->willReturn(null);

        return new FlowRouter($inner, $registry);
    }
}
