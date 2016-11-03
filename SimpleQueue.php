<?php
/**
 * User: Anatoly Skornyakov
 * Email: anatoly@skornyakov.net
 * Date: 01/11/2016
 * Time: 14:34
 */

declare(ticks = 1);

namespace fritool\SimpleQueueBundle;

use fritool\SimpleQueueBundle\Exception\WorkerClassNotFoundException;
use fritool\SimpleQueueBundle\Worker\WorkerAbstract;
use Predis\Client;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

class SimpleQueue
{
    const QUEUE_PREFIX = 'simple_queue';

    /**
     * @var bool
     */
    private $_run = true;

    /**
     * @var Client
     */
    private $_redisClient;

    /**
     * @var LoggerInterface
     */
    private $_logger;

    /**
     * @var SimpleQueue
     */
    private static $_instance;

    /**
     * @var array
     */
    private $_activeProcesses = [];

    /**
     * @var string
     */
    private $_pidFile;

    /**
     * @var
     */
    private $_allowedThreadCount;

    /**
     * SimpleQueue constructor.
     *
     * @param array $config
     */
    private function __construct(array $config)
    {
        $this->_redisClient        = new Client($config['redis']);
        $this->_pidFile            = $config['pid_file'];
        $this->_allowedThreadCount = $config['thread_count'];
    }

    /**
     * @param array                $redisConfig
     * @param LoggerInterface|null $logger
     *
     * @return SimpleQueue
     */
    public static function getInstance(
        array $redisConfig = [],
        LoggerInterface $logger = null
    )
    {
        if (is_null(self::$_instance)) {
            self::$_instance = new self($redisConfig);
            self::$_instance->setLogger($logger);
        }

        return self::$_instance;
    }

    public function setLogger(LoggerInterface $logger)
    {
        $this->_logger = $logger;
    }

    public function getLogger(): LoggerInterface
    {
        return $this->_logger;
    }

    public function addToQueue(WorkerAbstract $worker)
    {
        $key = $this->_getKeyForQueue();
        $worker->incReties();
        $workerData = json_encode($worker);
        $this->_redisClient->rpush($key, $workerData);
        $this->getLogger()->info(
            'Added job',
            ['worker_data' => $workerData]
        );
    }

    /**
     * Run worker
     */
    public function run()
    {
        $this->_writePidFile();
        $this->_registerSignals('stopWorker');
        $logger = $this->getLogger();
        $queueKey = $this->_getKeyForQueue();

        while($this->_run) {
            sleep(1);
            $this->_checkProcesses();
            if (!$this->_hasFreeThreads()) {
                $logger->debug('No free threads');
                continue;
            }

            $workerData = json_decode($this->_redisClient->lpop($queueKey));

            if (!is_null($workerData)) {
                $inProcessKey = $this->_getKeyForInProcess($workerData->jid);
                $this->_redisClient->set($inProcessKey, json_encode($workerData));
                $this->_runProcess($workerData->jid);
            }
        }

        $logger->alert('Please wait completion processes.');
        $this->_waitForCompletionAllProcess();
        $this->_removePidFile();
        $logger->alert('Worker was Stopped');
    }

    /**
     * @return bool
     */
    private function _hasFreeThreads(): bool
    {
        $count = count($this->_activeProcesses);
        $this->getLogger()->debug(
            sprintf('Active Processes Count: %d', $count)
        );

        return $count < $this->_allowedThreadCount;
    }

    /**
     * Checking active processes
     */
    private function _checkProcesses()
    {
        $logger = $this->getLogger();
        /** @var Process $process */
        foreach ($this->_activeProcesses as $jid => $process) {
            if ($process->isRunning()) {
                $logger->debug(
                    sprintf('Process is Running JID: %s', $jid),
                    ['jid' => $jid]
                );
            } else {
                if ($process->isSuccessful()) {
                    $logger->info(
                        'Process was completed successfully', ['jid' => $jid]
                    );
                    $key = $this->_getKeyForInProcess($jid);
                    $this->_redisClient->del([$key]);
                    $this->_statIntSuccessful();
                    $logger->debug(
                        sprintf('Removed from Redis by key: %s', $key),
                        ['jid' => $jid]
                    );
                } else {
                    $this->_statIntFailed();
                    $output = $process->getErrorOutput();
                    $logger->critical(
                        'Process is Failed', ['jid' => $jid, 'output' => $output]
                    );
                    $key = $this->_getKeyForInProcess($jid);
                    $workerData = json_decode($this->_redisClient->get($key));
                    $this->_redisClient->del([$key]);
                    $worker = $this->_getWorkerObject($workerData);

                    if ($worker->canRetry()) {
                        $this->addToQueue($worker);
                        $logger->info(
                            'Add to queue',
                            ['worker_data' => $workerData]
                        );
                    } else {
                        $logger->info(
                            'Doesn\'t add to queue',
                            ['worker_data' => $workerData]
                        );
                    }
                }
                unset($this->_activeProcesses[$jid]);
            }
        }
    }

    /**
     * Waiting for completion all active processes
     */
    private function _waitForCompletionAllProcess()
    {
        while (count($this->_activeProcesses) > 0) {
            sleep(1);
            $this->_checkProcesses();
        }
    }

    /**
     * @param string $methodName
     */
    private function _registerSignals(string $methodName)
    {
        pcntl_signal(SIGHUP, [$this, $methodName]);
        pcntl_signal(SIGINT, [$this, $methodName]);
        pcntl_signal(SIGQUIT, [$this, $methodName]);
        pcntl_signal(SIGTERM, [$this, $methodName]);
        pcntl_signal(SIGTSTP, [$this, $methodName]);
        pcntl_signal(SIGUSR1, [$this, $methodName]);
    }

    /**
     * Stop the cycle of worker
     * @param $sig
     */
    public function stopWorker($sig)
    {
        $this->getLogger()->alert(sprintf('Received signal: %s', $sig));
        $this->_run = false;
    }

    public function stopJob($sig)
    {
        $this->getLogger()->alert(sprintf('Received signal: %s', $sig));
    }

    /**
     * @param $jid
     */
    private function _runProcess($jid)
    {
        $process = new Process('php ./bin/console simple-queue:run-job -vvv ' . $jid);
        $process->enableOutput();
        $process->start();
        $this->_activeProcesses[$jid] = $process;

        $this->getLogger()->info(
            'Process run',
            [
                'jid'     => $jid,
                'pid'     => $process->getPid(),
                'command' => $process->getCommandLine(),
            ]
        );
    }

    /**
     * @param $jid
     *
     * @throws \Exception
     */
    public function runJob($jid)
    {
        $this->_registerSignals('stopJob');
        $logger = $this->getLogger();

        $logger->info(
            sprintf('Run Job by JID: %s', $jid),
            ['jid' => $jid]
        );

        $key = $this->_getKeyForInProcess($jid);
        $workerData = json_decode($this->_redisClient->get($key));

        if (is_null($workerData)) {
            $logger->warning(
                sprintf('Job Not Found by KEY: %s', $key),
                ['jid' => $jid]
            );

            return;
        }

        $worker = $this->_getWorkerObject($workerData);

        try {
            $worker->run();
        } catch (\Throwable $x) {
            $logger->critical(
                sprintf('Job Failed. JID: %s', $jid),
                [
                    'jid'   => $jid,
                    'error' => $x->getMessage(),
                ]
            );

            throw new \Exception($x->getMessage(), 1, $x);
        }

        $logger->info(
            sprintf('Job was finished JID: %s', $jid),
            ['jid' => $jid]
        );
    }

    /**
     * @param $workerData
     *
     * @return WorkerAbstract
     * @throws WorkerClassNotFoundException
     */
    private function _getWorkerObject($workerData): WorkerAbstract
    {
        $workerClassName = $workerData->worker_class_name;
        if (!class_exists($workerClassName)) {
            $errorMessage = sprintf(
                'Worker Class Not Found "%s"',
                $workerData->worker_class_name
            );
            $this->getLogger()->error(
                $errorMessage,
                [
                    'jid' => $workerData->jid,
                    'worker_data' => $workerData,
                ]
            );

            throw new WorkerClassNotFoundException($errorMessage);
        }

        /** @var WorkerAbstract $worker */
        $worker = $workerClassName::create($workerData, $this->getLogger());

        return $worker;
    }

    private function _statIntFailed()
    {
        $today = (new \DateTime())->format('Y-m-d');
        $key = sprintf('%s:stat:failed:%s', self::QUEUE_PREFIX, $today);
        $this->_redisClient->incr($key);
    }

    private function _statIntSuccessful()
    {
        $today = (new \DateTime())->format('Y-m-d');
        $key = sprintf('%s:stat:successful:%s', self::QUEUE_PREFIX, $today);
        $this->_redisClient->incr($key);
    }

    /**
     * @return string
     */
    private function _getKeyForQueue(): string
    {
        return sprintf('%s:%s', self::QUEUE_PREFIX, 'queue');
    }

    /**
     * @param $jid
     *
     * @return string
     */
    private function _getKeyForInProcess($jid): string
    {
        return sprintf('%s:%s:%s', self::QUEUE_PREFIX, 'in_process', $jid);
    }

    private function _writePidFile()
    {
        $this->_removePidFile();

        $dirName = dirname($this->_pidFile);
        if (!file_exists($dirName)) {
            $this->getLogger()->debug(
                sprintf('Create directory "%s"', $dirName)
            );
            mkdir($dirName, 0777, true);
        }

        file_put_contents($this->_pidFile, getmypid());
    }

    private function _removePidFile()
    {
        if (file_exists($this->_pidFile)) {
            unlink($this->_pidFile);
        }
    }
}