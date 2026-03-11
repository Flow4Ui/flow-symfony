<?php

namespace App\Tests\Service;

use Flow\Event\AfterFlowOptionsCompileEvent;
use Flow\Event\BeforeFlowOptionsCompileEvent;
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
}
