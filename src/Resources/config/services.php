<?php

use Flow\Asset\AssetPackage;
use Flow\Service\Manager;
use Flow\Service\Registry;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return function (ContainerConfigurator $configurator) {
    $services = $configurator->services()
        ->defaults()
            ->autowire(true)
            ->autoconfigure(true)
            ->public(false);

    $services->set(Registry::class)
        ->args([
            ('router.enabled'),
            ('router.mode'),
            ('router.base'),
        ])
        ->public()
        ->alias('flow.registry', Registry::class)
        ->public();

    $services->set(Manager::class)
        ->public();

    $services->set(AssetPackage::class)
        ->public()
        ->args([service('request_stack')])
        ->tag('assets.package', ['package' => 'flow.assets.package']);
};
