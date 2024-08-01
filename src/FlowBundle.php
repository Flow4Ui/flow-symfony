<?php

namespace Flow;

use Flow\{Attributes\Component,
    Attributes\State,
    Attributes\Store,
    Compiler\AttributeCompilerPass,
    DependencyInjection\FlowExtension
};
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use ReflectionClass;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\{ChildDefinition, ContainerBuilder, Extension\ExtensionInterface};
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

    public function boot(): void
    {
        $filesystem = new Filesystem();
        $sourceDir = __DIR__ . '/Resources/public';
        $targetDir = $this->container->getParameter('kernel.project_dir') . '/public/bundles/flow';

        if ($filesystem->exists($sourceDir)) {
            $filesystem->mirror($sourceDir, $targetDir);
        }
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
    protected function createContainerExtension(): ?ExtensionInterface
    {
        return new FlowExtension();
    }
}
