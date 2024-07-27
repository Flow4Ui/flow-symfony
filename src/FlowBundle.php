<?php

namespace Flow;

use Flow\Attributes\Component;
use Flow\Attributes\State;
use Flow\Attributes\Store;
use Flow\Compiler\AttributeCompilerPass;
use Flow\Service\Registry;
use ReflectionClass;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

class FlowBundle extends AbstractBundle
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

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $loader = new Loader\YamlFileLoader($builder, new FileLocator(__DIR__ . '/Resources/config'));
        $loader->load('services.yaml');

        $container->services()->get(Registry::class)
            ->arg(0, $config['router']['enabled'] ?? false)
            ->arg(1, $config['router']['mode'] ?? 'hash')
            ->arg(2, $config['router']['base'] ?? null);
    }

    public function build(ContainerBuilder $container): void
    {
        $stateConfigurator = static function (
            ChildDefinition $definition,
            State           $attribute,
            ReflectionClass $reflector
        ): void {
            $definition->addTag('flow.state', []);
        };


        $container->registerAttributeForAutoconfiguration(
            State::class,
            $stateConfigurator
        );
        $container->registerAttributeForAutoconfiguration(
            Store::class,
            $stateConfigurator
        );
        $container->registerAttributeForAutoconfiguration(
            Component::class,
            static function (
                ChildDefinition $definition,
                Component       $attribute,
                ReflectionClass $reflector
            ): void {
                if (!$definition->hasTag('flow.state')) {
                    $definition->addTag('flow.state', []);
                }
                $definition->addTag('flow.component', []);
            }
        );

        $container->addCompilerPass(new AttributeCompilerPass());
    }
}