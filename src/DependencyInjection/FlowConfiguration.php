<?php

namespace Flow\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class FlowConfiguration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('flow');
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->children()
                ->arrayNode('router')
                    ->children()
                        ->booleanNode('enabled')->defaultFalse()->end()
                        ->enumNode('mode')
                            ->values(['history', 'hash', 'memory'])
                            ->defaultValue('history')
                        ->end()
                        ->scalarNode('base')->defaultNull()->end()
                    ->end()
                ->end()
                ->scalarNode('language')->defaultValue('')->end()
                ->arrayNode('cache')
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->end()
                        ->scalarNode('dir')->defaultValue('%kernel.cache_dir%/flow')->end()
                    ->end()
                ->end()
                ->arrayNode('security')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('action_role_map')
                            ->defaultValue([])
                            ->useAttributeAsKey('name')
                            ->prototype('variable')
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}