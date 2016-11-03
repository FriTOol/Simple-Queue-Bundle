<?php

namespace fritool\SimpleQueueBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder();
        $rootNode = $treeBuilder->root('simple_queue');

        $rootNode
            ->children()
                ->arrayNode('redis')
                    ->isRequired()
                    ->children()
                        ->scalarNode('scheme')
                            ->end()
                        ->scalarNode('host')
                            ->end()
                        ->scalarNode('port')
                            ->end()
                        ->end()
                    ->end()
                ->scalarNode('pid_file')
                    ->defaultValue('var/pid/simple_queue.pid')
                    ->end()
                ->scalarNode('thread_count')
                    ->isRequired()
                    ->end()
        ;

        return $treeBuilder;
    }
}
