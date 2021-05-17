<?php

declare(strict_types=1);

namespace Yiisoft\Yii\Queue;

use InvalidArgumentException;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Yiisoft\Yii\Queue\Cli\LoopInterface;
use Yiisoft\Yii\Queue\Adapter\AdapterInterface;
use Yiisoft\Yii\Queue\Enum\JobStatus;
use Yiisoft\Yii\Queue\Event\AfterPush;
use Yiisoft\Yii\Queue\Event\BeforePush;
use Yiisoft\Yii\Queue\Exception\BehaviorNotSupportedException;
use Yiisoft\Yii\Queue\Message\MessageInterface;
use Yiisoft\Yii\Queue\Worker\WorkerInterface;

class Queue
{
    protected EventDispatcherInterface $eventDispatcher;
    protected AdapterInterface $adapter;
    protected WorkerInterface $worker;
    protected LoopInterface $loop;
    private LoggerInterface $logger;

    public function __construct(
        AdapterInterface $adapter,
        EventDispatcherInterface $dispatcher,
        WorkerInterface $worker,
        LoopInterface $loop,
        LoggerInterface $logger
    ) {
        $this->adapter = $adapter;
        $this->eventDispatcher = $dispatcher;
        $this->worker = $worker;
        $this->loop = $loop;
        $this->logger = $logger;
    }

    /**
     * Pushes a message into the queue.
     *
     * @param MessageInterface $message
     *
     * @throws BehaviorNotSupportedException
     */
    public function push(MessageInterface $message): void
    {
        $this->logger->debug('Preparing to push message "{message}".', ['message' => $message->getName()]);
        $this->eventDispatcher->dispatch(new BeforePush($this, $message));

        $this->adapter->push($message);

        $this->logger->debug(
            'Successfully pushed message "{name}" to the queue.',
            ['name' => $message->getName()]
        );

        $this->eventDispatcher->dispatch(new AfterPush($this, $message));
    }

    /**
     * Execute all existing jobs and exit
     *
     * @param int $max
     */
    public function run(int $max = 0): void
    {
        $this->logger->debug('Start processing queue messages.');
        $count = 0;

        $callback = function (MessageInterface $message) use (&$max, &$count): bool {
            if (($max > 0 && $max <= $count) || !$this->loop->canContinue()) {
                return false;
            }

            $this->handle($message);
            $count++;

            return true;
        };
        $this->adapter->runExisting($callback);

        $this->logger->debug(
            'Finish processing queue messages. There were {count} messages to work with.',
            ['count' => $count]
        );
    }

    /**
     * Listen to the queue and execute jobs as they come
     */
    public function listen(): void
    {
        $this->logger->debug('Start listening to the queue.');
        $this->adapter->subscribe(fn (MessageInterface $message) => $this->handle($message));
        $this->logger->debug('Finish listening to the queue.');
    }

    /**
     * @param string $id A message id
     *
     * @throws InvalidArgumentException when there is no such id in the adapter
     *
     * @return JobStatus
     */
    public function status(string $id): JobStatus
    {
        return $this->adapter->status($id);
    }

    protected function handle(MessageInterface $message): void
    {
        $this->worker->process($message, $this);
    }
}
