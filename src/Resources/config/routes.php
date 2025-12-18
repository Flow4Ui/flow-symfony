<?php

use Flow\Controller\FlowController;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return function (RoutingConfigurator $routes) {
    $routes->add('flow.endpoint', '/_flow/endpoint')
        ->controller([FlowController::class, 'endpoint'])
        ->methods(['POST']);

    $routes->add('flow.ssr', '/_flow/ssr/{component}')
        ->controller([FlowController::class, 'ssr'])
        ->defaults(['component' => null])
        ->methods(['GET']);
};
