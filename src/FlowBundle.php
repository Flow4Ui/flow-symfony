<?php

namespace Flow;

use Flow\{Compiler\AttributeCompilerPass};
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\{ContainerBuilder};
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class FlowBundle extends Bundle
{

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
            ->arrayNode('router')
            ->children()
            ->booleanNode('enabled')->defaultFalse()->end()
            ->enumNode('mode')->values(['history', 'hash', 'memory'])->defaultValue('hash')->end()
            ->scalarNode('base')->defaultNull()->end()
            ->end() // router-children
            ->end() // router
            ->scalarNode('language')->defaultValue('')->end()
            ->end();
    }

    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new AttributeCompilerPass());
    }
}
