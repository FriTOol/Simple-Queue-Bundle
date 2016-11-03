<?php
/**
 * User: Anatoly Skornyakov
 * Email: anatoly@skornyakov.net
 * Date: 02/11/2016
 * Time: 16:02
 */

namespace fritool\SimpleQueueBundle\Command;

use fritool\SimpleQueueBundle\SimpleQueue;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SimpleQueueWorkerCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('simple-queue:worker')
            ->setAliases(['sq:w'])
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        /** @var SimpleQueue $q */
        $q = $this->getContainer()->get('simple_queue');
        $q->run();
    }
}