<?php

declare(strict_types=1);

namespace Sauron\Bundle\DependencyInjection;

use Sauron\Bundle\Doctrine\SauronMiddleware;
use Sauron\Bundle\EventSubscriber\TraceSubscriber;
use Sauron\Bundle\SauronClient;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;

class SauronExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        if (!$config['enabled']) {
            return;
        }

        // SauronClient — holds span buffer + flushes on terminate
        $client = new Definition(SauronClient::class, [
            $config['endpoint'],
            $config['service_name'],
            $config['timeout_ms'],
        ]);
        $client->setPublic(false);
        $container->setDefinition(SauronClient::class, $client);

        // TraceSubscriber — creates controller spans
        $subscriber = new Definition(TraceSubscriber::class, [
            new Reference(SauronClient::class),
        ]);
        $subscriber->addTag('kernel.event_subscriber');
        $container->setDefinition(TraceSubscriber::class, $subscriber);

        // Doctrine middleware (only if doctrine/dbal is available)
        if ($config['instrument_doctrine']) {
            $middleware = new Definition(SauronMiddleware::class, [
                new Reference(SauronClient::class),
            ]);
            $middleware->addTag('doctrine.middleware');
            $container->setDefinition(SauronMiddleware::class, $middleware);
        }
    }

    public function getAlias(): string
    {
        return 'sauron';
    }
}
