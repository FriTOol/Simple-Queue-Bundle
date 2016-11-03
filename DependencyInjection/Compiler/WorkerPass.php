<?php
/**
 * User: Anatoly Skornyakov
 * Email: anatoly@skornyakov.net
 * Date: 03/11/2016
 * Time: 14:50
 */

namespace fritool\SimpleQueueBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Reference;

class WorkerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container)
    {
        if (!$container->has('simple_queue')) {
            return;
        }

        $qDefinition    = $container->findDefinition('simple_queue');
        $taggedServices = $container->findTaggedServiceIds('simple_queue.worker');

        $workerMap = [];
        foreach ($taggedServices as $id => $tags) {
            $workerDefinition = $container->getDefinition($id);
            $workerMap[$workerDefinition->getClass()] = new Reference($id);
        }

        if (count($workerMap) > 0) {
            $qDefinition->addMethodCall('setWorkerMap', array($workerMap));
        }
    }
}