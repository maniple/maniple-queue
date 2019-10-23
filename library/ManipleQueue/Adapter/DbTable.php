<?php

class ManipleQueue_Adapter_DbTable extends Zend_Queue_Adapter_Db
{
    const className = __CLASS__;

    /**
     * @param Zefram_Db|Zend_Config|array $options
     * @param Zend_Queue|null $queue
     * @throws Zend_Queue_Exception
     */
    public function __construct($options, Zend_Queue $queue = null)
    {
        if ($options instanceof Zefram_Db) {
            $db = $options;
            $options = array(
                'dbAdapter'   => $db->getAdapter(),
                'tablePrefix' => $db->getTablePrefix(),
                // Zend_Db_Select::FOR_UPDATE =>
            );
        }

        if (isset($options['tablePrefix'])) {
            $tablePrefix = $options['tablePrefix'];
            unset($options['tablePrefix']);
        } else {
            $tablePrefix = '';
        }

        parent::__construct($options, $queue);

        $this->_queueTable->setOptions(array(
            Zend_Db_Table_Abstract::NAME => $tablePrefix . 'queues',
        ));
        $this->_messageTable->setOptions(array(
            Zend_Db_Table_Abstract::NAME => $tablePrefix . 'queue_messages',
        ));
    }

    public function create($name, $timeout = null)
    {
        if ($this->isExists($name)) {
            return false;
        }

        $queue = $this->_queueTable->createRow();
        $queue->queue_name = $name;
        $queue->timeout    = ($timeout === null) ? self::CREATE_TIMEOUT_DEFAULT : intval($timeout);

        try {
            if ($queue->save()) {
                // missing in Zend_Queue_Db_Adapter
                $this->_queues[$name] = $queue->queue_id;
                return true;
            }
        } catch (Exception $e) {
            throw new Zend_Queue_Exception($e->getMessage(), $e->getCode(), $e);
        }

        return false;
    }

    /**
     * Get messages in the queue
     *
     * @param  integer $maxMessages Maximum number of messages to return
     * @param  integer $timeout Lock timeout in seconds
     * @param  Zend_Queue $queue
     * @return Zend_Queue_Message_Iterator
     * @throws
     */
    public function receive($maxMessages = null, $timeout = null, Zend_Queue $queue = null)
    {
        $maxMessages = ($maxMessages === null) ? 1 : intval($maxMessages);
        if ($maxMessages <= 0) {
            throw new Zend_Queue_Exception('Invalid number of messages to receive');
        }

        $timeout = ($timeout === null) ? self::RECEIVE_TIMEOUT_DEFAULT : intval($timeout);
        if ($timeout <= 0) {
            throw new Zend_Queue_Exception('Invalid timeout time');
        }

        // convert timeout seconds to milliseconds, to match 'timeout' column
        $timeout *= 1000;

        if ($queue === null) {
            $queue = $this->_queue;
        }

        $messages  = array();
        $nowMillis = floor(microtime(true) * 1000);
        $db        = $this->_messageTable->getAdapter();

        $queueTableName = $this->_queueTable->info(Zend_Db_Table::NAME);
        $messageTableName = $this->_messageTable->info(Zend_Db_Table::NAME);

        try {
            $db->beginTransaction();

            $queueId = $this->getQueueId($queue->getName());

            $query = $db->select();
            if ($this->_options['options'][Zend_Db_Select::FOR_UPDATE]) {
                $query->forUpdate();
            }
            $query->from($messageTableName, array('*'))
                ->where('queue_id = ?', $queueId)
                ->where('handle IS NULL OR timeout + ' . $timeout . ' < ' . $nowMillis)
                ->order(array('priority DESC', 'created ASC'))
                ->limit($maxMessages);

            foreach ($db->fetchAll($query) as $data) {
                // prepare message for locking
                $data['handle'] = md5(uniqid(rand(), true));

                $update = array(
                    'handle'  => $data['handle'],
                    'timeout' => $nowMillis,
                );

                $where   = array();
                $where[] = $db->quoteInto('message_id = ?', $data['message_id']);
                $where[] = 'handle IS NULL OR timeout + ' . $timeout . ' < ' . $nowMillis;

                $count = $db->update($messageTableName, $update, $where);

                // we check count to make sure no other thread has gotten
                // the rows after our select, but before our update.
                if ($count > 0) {
                    $messages[] = $data;
                }
            }
            $db->update(
                $queueTableName,
                array('received' => new Zend_Db_Expr(sprintf(
                    'CASE WHEN %s > received THEN %s ELSE received END', $nowMillis, $nowMillis
                ))),
                array('queue_id = ?' => $queueId)
            );
            $db->commit();

        } catch (Exception $e) {
            $db->rollBack();
            throw new Zend_Queue_Exception($e->getMessage(), $e->getCode(), $e);
        }

        $options = array(
            'queue'        => $queue,
            'data'         => $messages,
            'messageClass' => $queue->getMessageClass(),
        );

        $className = $queue->getMessageSetClass();
        if (!class_exists($className)) {
            Zend_Loader::loadClass($className);
        }
        return new $className($options);
    }

    /*
     * Get an array of all available queues
     *
     * @return string[]
     */
    public function getQueues()
    {
        $query = $this->_queueTable->select();
        $query->from($this->_queueTable, array('queue_id', 'queue_name'));

        // Order queues by oldest received first - to prevent queue starvation
        // in workers that cyclically iterate through queues and process messages
        $query->order('received ASC');

        $this->_queues = array();
        foreach ($this->_queueTable->fetchAll($query) as $queue) {
            $this->_queues[$queue->queue_name] = intval($queue->queue_id);
        }

        $list = array_keys($this->_queues);

        return $list;
    }
}
