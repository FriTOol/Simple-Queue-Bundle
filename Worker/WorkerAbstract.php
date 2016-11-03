<?php
/**
 * User: Anatoly Skornyakov
 * Email: anatoly@skornyakov.net
 * Date: 01/11/2016
 * Time: 14:38
 */

namespace fritool\SimpleQueueBundle\Worker;

use fritool\SimpleQueueBundle\SimpleQueue;
use Psr\Log\LoggerInterface;

abstract class WorkerAbstract implements \JsonSerializable
{
    /**
     * @var null|array
     */
    protected $_args;

    /**
     * @var string
     */
    protected $_jid;

    /**
     * @var \DateTime
     */
    protected $_createdAt;

    /**
     * @var LoggerInterface
     */
    protected $_logger;

    /**
     * @var int
     */
    protected $_allowRetries = 5;

    /**
     * @var int
     */
    protected $_numOfRetries  = 0;

    public abstract function perform(array $args = []);

    /**
     * WorkerAbstract constructor.
     *
     * @param $args
     */
    private function __construct($args)
    {
        $this->_args = $args;
        $this->_jid = uniqid();
        $this->_createdAt = new \DateTime();
    }

    /**
     * @param array ...$args
     */
    public static function addToQ(...$args)
    {
        $self = new static($args);
        $self->addToQueue();
    }

    /**
     * @param                      $workerData
     * @param LoggerInterface|null $logger
     *
     * @return WorkerAbstract
     */
    public static function create(
        $workerData, LoggerInterface $logger = null
    ): WorkerAbstract
    {
        $self = new static($workerData->args);
        $self->setWorkerData($workerData);
        if ($logger) {
            $self->setLogger($logger);
        }

        return $self;
    }

    public function addToQueue()
    {
        SimpleQueue::getInstance()->addToQueue($this);
    }

    public function run()
    {
        $this->perform($this->_args);
    }

    /**
     * @return bool
     */
    public function canRetry(): bool
    {
        return $this->_allowRetries != 0 && $this->_numOfRetries <= $this->_allowRetries;
    }

    public function incReties()
    {
        $this->_numOfRetries++;
    }

    public function setWorkerData($workerData)
    {
        $this->_args = $workerData->args;
        $this->_jid = $workerData->jid;
        $this->_createdAt = (new \DateTime())->setTimestamp($workerData->created_at);
        $this->_numOfRetries = $workerData->num_of_retries;
    }

    public function setLogger(LoggerInterface $logger)
    {
        $this->_logger = $logger;
    }

    public function getLogger(): LoggerInterface
    {
        return $this->_logger;
    }

    public function jsonSerialize()
    {
        return [
            'args' => $this->_args,
            'jid'  => $this->_jid,
            'created_at' => $this->_createdAt->getTimestamp(),
            'worker_class_name' => static::class,
            'num_of_retries' => $this->_numOfRetries,
        ];
    }
}