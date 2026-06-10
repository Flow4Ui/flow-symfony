<?php

namespace App\Tests\Service;

use Flow\Attributes\Component;
use Flow\Attributes\Router;
use Flow\Event\AfterFlowOptionsCompileEvent;
use Flow\Event\BeforeFlowOptionsCompileEvent;
use Flow\Routing\RouteDefinition;
use Flow\Service\Manager;
use Flow\Service\Registry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Twig\Environment;
use Flow\Contract\Transport;

class ManagerTest extends TestCase
{
    public function testCompileJsFlowOptionsDispatchesBeforeAndAfterEvents(): void
    {
        $registry = $this->createMock(Registry::class);
        $registry->method('isRouterEnabled')->willReturn(true);
        $registry->method('getRoutes')->willReturn([
            ['path' => '/login', 'name' => 'login', 'component' => 'LoginPage'],
        ]);
        $registry->method('getRouterMode')->willReturn('history');
        $registry->method('getRouterBase')->willReturn('/app');

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher
            ->expects($this->exactly(2))
            ->method('dispatch')
            ->willReturnCallback(function (object $event, ?string $eventName = null) {
                if ($event instanceof BeforeFlowOptionsCompileEvent) {
                    $this->assertSame(BeforeFlowOptionsCompileEvent::NAME, $eventName);
                    $event->setOptions([
                        'components' => [],
                        'stores' => [],
                        'states' => [],
                        'mount' => '#custom-app',
                    ]);

                    return $event;
                }

                if ($event instanceof AfterFlowOptionsCompileEvent) {
                    $this->assertSame(AfterFlowOptionsCompileEvent::NAME, $eventName);
                    $flowOptions = $event->getFlowOptions();
                    $flowOptions['router']['routes'][] = [
                        'path' => '/forgot-password',
                        'name' => 'forgot_password',
                        'component' => 'ForgotPasswordPage',
                    ];
                    $flowOptions['customized'] = true;
                    $event->setFlowOptions($flowOptions);

                    return $event;
                }

                $this->fail('Unexpected event dispatched.');
            });

        $manager = new Manager(
            [],
            [],
            $this->createMock(NormalizerInterface::class),
            $this->createMock(DenormalizerInterface::class),
            $registry,
            $this->createMock(Environment::class),
            $this->createMock(Transport::class),
            $dispatcher,
        );

        $compiled = $manager->compileJsFlowOptions([
            'components' => [],
            'stores' => [],
            'states' => [],
        ]);

        $decoded = json_decode($compiled, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('#custom-app', $decoded['mount']);
        $this->assertTrue($decoded['customized']);
        $this->assertCount(2, $decoded['router']['routes']);
        $this->assertSame('forgot_password', $decoded['router']['routes'][1]['name']);
    }

    public function testCompileJsFlowOptionsSerializesRouteDefinitions(): void
    {
        $registry = $this->createMock(Registry::class);
        $registry->method('isRouterEnabled')->willReturn(true);
        $registry->method('getRoutes')->willReturn([
            new RouteDefinition(
                path: '/product-crud',
                name: 'admin.catalog.product',
                component: 'ProductCrud',
                props: ['mode' => 'index'],
                meta: ['section' => 'catalog'],
                children: [
                    new RouteDefinition(path: 'create', name: 'admin.catalog.product.create', component: 'ProductCreatePage'),
                ],
            ),
        ]);
        $registry->method('getRouterMode')->willReturn('history');
        $registry->method('getRouterBase')->willReturn('/app');

        $manager = new Manager(
            [],
            [],
            $this->createMock(NormalizerInterface::class),
            $this->createMock(DenormalizerInterface::class),
            $registry,
            $this->createMock(Environment::class),
            $this->createMock(Transport::class),
        );

        $compiled = $manager->compileJsFlowOptions([
            'components' => [],
            'stores' => [],
            'states' => [],
        ]);

        $decoded = json_decode($compiled, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame([
            [
                'path' => '/product-crud',
                'name' => 'admin.catalog.product',
                'component' => 'ProductCrud',
                'props' => ['mode' => 'index'],
                'meta' => ['section' => 'catalog'],
                'children' => [
                    [
                        'path' => 'create',
                        'name' => 'admin.catalog.product.create',
                        'component' => 'ProductCreatePage',
                        'props' => true,
                    ],
                ],
            ],
        ], $decoded['router']['routes']);
    }

    public function testCompileJsFlowOptionsSerializesParentLinkedRoutesAsChildren(): void
    {
        $registry = new Registry(routerEnabled: true, routerMode: 'history', routerBase: '/app');
        $registry->defineComponent(new \ReflectionClass(ManagerCatalogPage::class));
        $registry->defineComponent(new \ReflectionClass(ManagerProductIndexPage::class));

        $manager = new Manager(
            [],
            [],
            $this->createMock(NormalizerInterface::class),
            $this->createMock(DenormalizerInterface::class),
            $registry,
            $this->createMock(Environment::class),
            $this->createMock(Transport::class),
        );

        $compiled = $manager->compileJsFlowOptions([
            'components' => [],
            'stores' => [],
            'states' => [],
        ]);

        $decoded = json_decode($compiled, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame([
            [
                'path' => '/catalog',
                'name' => 'manager.catalog',
                'component' => 'ManagerCatalogPage',
                'props' => true,
                'children' => [
                    [
                        'path' => 'product-crud',
                        'name' => 'manager.catalog.product.index',
                        'component' => 'ManagerProductIndexPage',
                        'props' => ['mode' => 'index'],
                    ],
                ],
            ],
        ], $decoded['router']['routes']);
    }

    public function testCompiledTemplateCacheIsInvalidatedWhenCompilerVersionChanges(): void
    {
        $cacheDir = sys_get_temp_dir() . '/flow-manager-cache-' . bin2hex(random_bytes(6));
        mkdir($cacheDir, 0777, true);

        $component = new ManagerCacheProbe();
        $registry = new Registry();
        $registry->defineComponent(new \ReflectionClass($component));

        $staleCacheFile = $cacheDir . '/' . ManagerCacheProbe::className() . '_' . md5(ManagerCacheProbe::class) . '.php';
        $oldTemplateOnlyHash = md5(ManagerCacheProbe::template());
        file_put_contents($staleCacheFile, <<<PHP
<?php

return [
    0 => 'return h("div", null, "STALE RENDER");',
    1 => null,
    2 => [],
    3 => '{$oldTemplateOnlyHash}',
];
PHP);

        $manager = new Manager(
            [$component],
            [$component],
            $this->createMock(NormalizerInterface::class),
            $this->createMock(DenormalizerInterface::class),
            $registry,
            $this->createMock(Environment::class),
            $this->createMock(Transport::class),
            cacheEnabled: true,
            cacheDir: $cacheDir,
        );

        $compiled = $manager->compileJsFlowOptions([
            'components' => [ManagerCacheProbe::className() => true],
            'stores' => [],
            'states' => [],
        ]);

        self::assertStringNotContainsString('STALE RENDER', $compiled);
        self::assertStringContainsString('Fresh cache title', $compiled);
    }
}

#[Component(name: 'ManagerCatalogPage')]
#[Router(path: '/catalog', name: 'manager.catalog')]
final class ManagerCatalogPage
{
}

#[Component(name: 'ManagerProductIndexPage')]
#[Router(
    parent: ManagerCatalogPage::class,
    path: 'product-crud',
    name: 'manager.catalog.product.index',
    props: ['mode' => 'index'],
)]
final class ManagerProductIndexPage
{
}

#[Component(name: 'ManagerCacheProbe', template: '<div>Fresh cache title</div>')]
final class ManagerCacheProbe
{
    public static function className(): string
    {
        return 'ManagerCacheProbe';
    }

    public static function template(): string
    {
        return '<div>Fresh cache title</div>';
    }
}
