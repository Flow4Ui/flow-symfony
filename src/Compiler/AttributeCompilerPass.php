<?php

namespace Flow\Compiler;

use Flow\Service\Registry;
use Symfony\Component\DependencyInjection\Compiler;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class AttributeCompilerPass implements Compiler\CompilerPassInterface
{

    public function process(ContainerBuilder $container)
    {

        $definitionManager = $container->findDefinition(Registry::class);
        foreach ($container->findTaggedServiceIds('flow.state') as $serviceId => $attributes) {
            $definitionManager->addMethodCall('defineStateByClassName', [$serviceId]);
        }

        foreach ($container->findTaggedServiceIds('flow.component') as $serviceId => $attributes) {
            $definitionManager->addMethodCall('defineComponentByClassName', [$serviceId]);
        }
    }
}