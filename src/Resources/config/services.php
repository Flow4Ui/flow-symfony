<?php

use Flow\Asset\AssetPackage;
use Flow\Command\InstallFlowAssetsCommand;
use Flow\Contract\SecurityInterface;
use Flow\Contract\Transport;
use Flow\Security\RoleBasedSecurity;
use Flow\Service\{FlowComponentCacheWarmer, FlowTwigExtension, Manager, Registry};
use Flow\Transport\AjaxJsonTransport;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\{param, service};

return function (ContainerConfigurator $configurator) {
    $services = $configurator->services()
        ->defaults()
            ->autowire(true)
            ->autoconfigure(true)
            ->public(false);


    $services->set('flow.transport.ajax_json', AjaxJsonTransport::class)
        ->args(
            [service('serializer')]
        )
        ->alias(AjaxJsonTransport::class, 'flow.transport.ajax_json');

    $services->set(Transport::class)
        ->alias(Transport::class, AjaxJsonTransport::class);

    $services->set('flow.security.role_based', RoleBasedSecurity::class)
        ->args([
            service('security.authorization_checker'),
            param('flow.action_role_map'),
            param('flow.component_role_map'),
            param('flow.security.component_enabled'),
        ])
        ->alias(RoleBasedSecurity::class, 'flow.security.role_based');;

    $services->set(SecurityInterface::class)
        ->alias(SecurityInterface::class, RoleBasedSecurity::class);

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
        ->arg('$componentSecurityEnabled', param('flow.security.component_enabled'))
        ->arg('$unauthorizedRoute', param('flow.security.unauthorized_route'))
        ->arg('$loginRoute', param('flow.security.login_route'))
        ->public();


    $services->set(AssetPackage::class)
        ->public()
        ->args([service('request_stack')])
        ->tag('assets.package', ['package' => 'flow.assets.package']);

    $services->set(FlowComponentCacheWarmer::class)
        ->tag('kernel.cache_warmer');
    $services->set(FlowTwigExtension::class);

    $services->set(InstallFlowAssetsCommand::class)
        ->arg('$projectDir', param('kernel.project_dir'))
        ->tag('console.command');
};
