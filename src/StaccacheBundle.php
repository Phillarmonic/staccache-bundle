<?php

namespace Phillarmonic\StaccacheBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class StaccacheBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // Register compiler passes if any
        // $container->addCompilerPass(new YourCompilerPass());
    }
}