<?php

class ManipleQueue_Tool_Provider_QueueWorker extends Maniple_Tool_Provider_Abstract
{
    const className = __CLASS__;

    /**
     * Execute queue worker
     *
     * @param int $numMessages Maximum number of messages to process
     * @throws Exception
     */
    public function exec($numMessages = 1)
    {
        $n = (int) $numMessages;
        if ($n <= 0) {
            throw new Zend_Tool_Project_Provider_Exception(sprintf(
                'Invalid number of messages to process: %s', $numMessages
            ));
        }

        $application = $this->_getApplication()->bootstrap();

        /** @var ManipleQueue_Service $queueService */
        $queueService = $application->getBootstrap()->getResource('Maniple.Queue');

        set_time_limit(0);

        $logger = $application->getBootstrap()->getResource('Log');
        if (!$logger) {
            $logger = Zefram_Log::factory(array(
                'registerErrorHandler' => true,
            ));
        }
        $logger->addWriter(new Zend_Log_Writer_Stream('php://output'));

        $queueService->setLogger($logger);
        $queueService->getEventManager()->attach('message', function (ManipleQueue_MessageEvent $event) {
            echo '[message] Received message from queue: ', $event->getMessage()->getQueue()->getName(), "\n";
        });
        $queueService->process($numMessages);
    }
}
