<?php

declare(strict_types=1);

namespace Sauron\Bundle;

use Sauron\Bundle\DependencyInjection\SauronExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class SauronBundle extends Bundle
{
    public function getContainerExtension(): SauronExtension
    {
        return new SauronExtension();
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
    }
}
