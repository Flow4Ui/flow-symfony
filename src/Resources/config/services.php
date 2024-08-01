<?php

use Flow\Asset\AssetPackage;
use Flow\Service\Registry;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return function (ContainerConfigurator $configurator) {
    $services = $configurator->services()
        ->defaults()
            ->autowire(true)
            ->autoconfigure(true)
            ->public(false);

    $services->set(Registry::class)
        ->args([
            param('router.enabled'),
            param('router.mode'),
            param('router.base'),
        ])
        ->public()
        ->alias('flow.registry', Registry::class)
        ->public();

    $services->set(AssetPackage::class)
        ->public()
        ->args(['@request_stack'])
        ->tag('assets.package', ['package' => 'flow.assets.package']);
};
