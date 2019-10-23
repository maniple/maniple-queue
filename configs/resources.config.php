<?php

return array(
    'Maniple.Queue' => array(
        'class' => ManipleQueue_Service::className,
        'args'  => array(
            'resource:Maniple.QueueAdapter',
            'resource:SharedEventManager',
            'resource:Log',
        ),
    ),
    'Maniple.QueueAdapter' => array(
        'class' => ManipleQueue_Adapter_DbTable::className,
        'args' => array(
            'resource:Zefram_Db',
        ),
    ),
);
