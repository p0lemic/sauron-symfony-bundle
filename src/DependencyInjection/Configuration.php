<?php

declare(strict_types=1);

namespace Sauron\Bundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tree = new TreeBuilder('sauron');
        $root = $tree->getRootNode();

        $root
            ->children()
                ->booleanNode('enabled')
                    ->defaultTrue()
                    ->info('Set to false to disable all Sauron instrumentation.')
                ->end()
                ->scalarNode('endpoint')
                    ->defaultValue('http://localhost:9090/ingest/spans')
                    ->info('URL of the Sauron dashboard ingest endpoint.')
                ->end()
                ->scalarNode('service_name')
                    ->defaultValue('symfony-app')
                    ->info('Identifies this service in the Sauron UI.')
                ->end()
                ->booleanNode('instrument_doctrine')
                    ->defaultTrue()
                    ->info('Automatically create a span for every Doctrine DBAL query.')
                ->end()
                ->integerNode('timeout_ms')
                    ->defaultValue(2000)
                    ->info('HTTP timeout (ms) when flushing spans to Sauron.')
                ->end()
            ->end();

        return $tree;
    }
}
