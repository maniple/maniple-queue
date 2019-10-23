<?php

class ManipleQueue_MessageEvent extends Zend_EventManager_Event
{
    const className = __CLASS__;

    /**
     * @var Zend_Queue_Message
     */
    protected $_message;

    /**
     * @return Zend_Queue_Message
     */
    public function getMessage()
    {
        return $this->_message;
    }

    /**
     * @param Zend_Queue_Message $message
     * @return $this
     */
    public function setMessage(Zend_Queue_Message $message)
    {
        $this->_message = $message;
        return $this;
    }

    /**
     * @param int|string $name
     * @param mixed $default
     * @return mixed
     */
    public function getParam($name, $default = null)
    {
        switch ($name) {
            case 'message':
                return $this->getMessage();
            default:
                return parent::getParam($name, $default);
        }
    }
}
