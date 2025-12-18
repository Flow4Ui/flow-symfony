<?php

namespace App\Tests\Service;

use Flow\Service\SsrRenderer;
use PHPUnit\Framework\TestCase;

class SsrRendererTest extends TestCase
{
    public function testRendersComponentHtml(): void
    {
        $renderer = new SsrRenderer(
            projectDir: dirname(__DIR__, 2),
            nodeBinary: 'node',
            enabled: true,
        );

        $flowOptions = [
            'definitions' => [
                'components' => [
                    'SsrHello' => [
                        'name' => 'SsrHello',
                        'stateId' => 'SsrHelloState',
                        'props' => [],
                        'state' => ['message' => 'SSR hello'],
                        'render' => 'return h("div", null, this.message);',
                        'methods' => [],
                    ],
                ],
                'states' => [
                    'SsrHelloState' => [
                        'name' => 'SsrHelloState',
                        'className' => 'SsrHelloState',
                        'properties' => [
                            'message' => [
                                'direction' => 'Booth',
                                'name' => 'message',
                            ],
                        ],
                        'state' => ['message' => 'SSR hello'],
                        'actions' => [],
                        'methods' => [],
                    ],
                ],
                'stores' => [],
            ],
            'router' => [
                'enabled' => false,
                'routes' => [],
                'mode' => 'history',
                'base' => null,
            ],
            'security' => [
                'componentSecurity' => false,
                'unauthorizedRoute' => null,
                'loginRoute' => null,
                'accessDeniedComponents' => [],
                'redirect' => null,
            ],
            'autoloadComponents' => '*',
            'mainComponent' => 'SsrHello',
        ];

        $html = $renderer->render($flowOptions, 'SsrHello');

        $this->assertStringContainsString('<div>SSR hello</div>', $html);
    }
}
