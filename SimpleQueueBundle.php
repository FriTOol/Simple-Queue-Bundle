<?php

namespace fritool\SimpleQueueBundle;

use fritool\SimpleQueueBundle\DependencyInjection\Compiler\WorkerPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class SimpleQueueBundle extends Bundle
{
    public function build(ContainerBuilder $container)
    {
        $container->addCompilerPass(new WorkerPass());
    }
}