<?php

use Flow\Asset\AssetPackage;
use Flow\Service\FlowComponentCacheWarmer;
use Flow\Service\FlowTwigExtension;
use Flow\Service\Manager;
use Flow\Service\Registry;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

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

    $services->set(Manager::class, Manager::class)
        ->arg('$cacheEnabled', param('flow.cache.enabled'))
        ->arg('$cacheDir', param('flow.cache.dir'))
        ->public();


    $services->set(AssetPackage::class)
        ->public()
        ->args([service('request_stack')])
        ->tag('assets.package', ['package' => 'flow.assets.package']);

    $services->set(FlowComponentCacheWarmer::class)
        ->tag('kernel.cache_warmer');
    $services->set(FlowTwigExtension::class);
};
