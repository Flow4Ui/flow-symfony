<?php

namespace Flow\DependencyInjection;

use Flow\Attributes\Component;
use Flow\Attributes\State;
use Flow\Attributes\Store;
use ReflectionClass;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

class FlowExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container)
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

        $loader = new PhpFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.php');
    }
}
