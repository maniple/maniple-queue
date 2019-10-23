<?php

class ManipleQueue_Service
{
    const className = __CLASS__;

    /**
     * @var Zend_Queue_Adapter_AdapterInterface
     */
    protected $_adapter;

    /**
     * @var Zend_Queue[]
     */
    protected $_queues;

    /**
     * @var Zend_EventManager_EventManager
     */
    protected $_events;

    /**
     * @var Zend_Log
     */
    protected $_logger;

    /**
     * @param Zend_Queue_Adapter_AdapterInterface $adapter
     * @param Zend_EventManager_SharedEventManager $sharedEvents
     * @param Zend_Log $logger
     */
    public function __construct(Zend_Queue_Adapter_AdapterInterface $adapter, Zend_EventManager_SharedEventManager $sharedEvents, Zend_Log $logger = null)
    {
        $this->_adapter = $adapter;

        $this->_events = new Zend_EventManager_EventManager();
        $this->_events->setIdentifiers(array(
            __CLASS__,
            get_class($this),
            'Maniple.Queue',
        ));
        $this->_events->setSharedCollections($sharedEvents);
        $this->_events->setEventClass(ManipleQueue_MessageEvent::className);

        $this->_logger = $logger;
    }

    /**
     * @return Zend_EventManager_EventManager
     */
    public function getEventManager()
    {
        return $this->_events;
    }

    /**
     * @param Zend_Log $logger
     * @return $this
     */
    public function setLogger(Zend_Log $logger)
    {
        $this->_logger = $logger;
        return $this;
    }

    /**
     * @return Zend_Log
     */
    public function getLogger()
    {
        return $this->_logger;
    }

    /**
     * Process messages from all queues
     *
     * @param int $maxMessages maximum number of messages to process
     * @throws Zend_Queue_Exception
     */
    public function process($maxMessages = 1)
    {
        $queues = $this->_adapter->getQueues();

        if (!count($queues) && $this->_logger) {
            $this->_logger->info('No queues found');
        }

        do {
            $messagesReceived = 0;
            foreach ($queues as $queueName) {
                $queue = $this->openQueue($queueName);

                /** @var Zend_Queue $queue */
                $message = $this->_adapter->receive(1, null, $queue)->current();

                if ($message === null) {
                    if ($this->_logger) {
                        $this->_logger->info(sprintf("Queue %s is empty", $queueName));
                    }
                    continue;
                }

                $messagesReceived++;

                $event = new ManipleQueue_MessageEvent();
                $event->setMessage($message);

                if ($this->_logger) {
                    $this->_logger->info(sprintf("Received message from queue %s", $queueName));
                }

                $this->_events->trigger('message.' . $queue->getName(), $this, $event);

                // if message hasn't been removed from the queue trigger wildcard event
                if ($message->getQueue()) {
                    if ($this->_logger) {
                        $this->_logger->info(sprintf("Triggering 'message' event on %s", $queueName));
                    }
                    $this->_events->trigger('message', $this, $event);
                } else {
                    if ($this->_logger) {
                        $this->_logger->info(sprintf("Message was removed, no 'message' event on %s", $queueName));
                    }
                }

                if ($messagesReceived >= $maxMessages) {
                    if ($this->_logger) {
                        $this->_logger->info(sprintf("Limit of %d processed messages reached", $maxMessages));
                    }
                    break;
                }
            }
        } while ($messagesReceived > 0);
    }

    /**
     * @param string $name
     * @return Zend_Queue
     * @throws Zend_Queue_Exception
     */
    public function openQueue($name)
    {
        if (empty($this->_queues[$name])) {
            $this->_adapter->create($name);
            $this->_queues[$name] = new Zend_Queue($this->_adapter, array('name' => $name));
        }
        return $this->_queues[$name];
    }

    /**
     * Send message to selected queue. Non-scalar messages will be JSON encoded
     *
     * @param string $queue  queue name
     * @param mixed $message message body
     * @return $this
     * @throws Zend_Queue_Exception
     */
    public function sendMessage($queue, $message)
    {
        if (!is_scalar($message)) {
            $message = Zefram_Json::encode($message, array('unencodedSlashes' => true));
        }

        $queue = $this->openQueue($queue);
        $queue->send($message);

        return $this;
    }

    /**
     * Add message listener
     *
     * Listener is a function accepting {@link Zend_Queue Message} as param.
     * If TRUE is returned from the listener, the message will be removed.
     *
     * If a listener is provided instead of a queue name, it will be registered
     * as listener to 'message' event. Otherwise a 'message.{queue}' event will
     * be listened to.
     *
     * @param string|callable $queue queue name or message listener
     * @param callable $listener     message listener
     * @return $this
     * @throws Zend_EventManager_Exception_InvalidArgumentException
     */
    public function addListener($queue, $listener = null)
    {
        if (is_string($queue)) {
            $eventName = 'message.' . $queue;
        } else {
            $listener = $queue;
            $eventName = 'message';
        }
        $callable = new Zefram_Stdlib_CallbackHandler($listener);
        $self = $this;

        $this->_events->attach($eventName, function (ManipleQueue_MessageEvent $event) use ($callable, $self) {
            $message = $event->getMessage();

            try {
                $result = $callable->invoke($message);

                if ($result === true) {
                    $event->stopPropagation(true);
                    $message->getQueue()->deleteMessage($message);
                }
            } catch (Exception $e) {
                $logger = $self->getLogger();
                if ($logger) {
                    $logger->err(sprintf('Maniple.Queue: Exception in %s listener: %s', $event->getName(), $e->getMessage()));
                }
            }
        });

        return $this;
    }
}
