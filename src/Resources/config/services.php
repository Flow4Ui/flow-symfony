<?php

use Flow\Service\Registry;
use Flow\Asset\AssetPackage;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return function (ContainerConfigurator $configurator) {
    $services = $configurator->services()
        ->defaults()
            ->autowire(true)
            ->autoconfigure(true)
            ->public(false);

    $services->set(Registry::class)
        ->alias('flow.registry', Registry::class)
        ->public();

    $services->set(AssetPackage::class)
        ->public()
        ->args(['@request_stack'])
        ->tag('assets.package', ['package' => 'flow.assets.package']);
};
