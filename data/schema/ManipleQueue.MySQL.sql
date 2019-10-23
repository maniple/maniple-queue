/*
  Zend_Queue_Adapter_Db compatible tables

  Differences from Zend_Queue_Adapter_Db schema:
  - Different naming conventions (queues, queue_messages instead of queue, message)
  - Drop limits on message body - TEXT instead of VARCHAR(8192)
  - Lock timeout stored as BIGINT instead of DECIMAL
  - Proper UTF-8 charset - utf8mb4 instead of non-standard utf8
  - Added priority column to messages table
  - Added received column to queues table
*/

CREATE TABLE queues (

    queue_id        INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,

    queue_name      VARCHAR(255) NOT NULL,

    timeout         SMALLINT UNSIGNED NOT NULL DEFAULT 30,

    -- last time messages has been received from this queue, in milliseconds
    received        BIGINT UNSIGNED DEFAULT 0

) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;


CREATE TABLE queue_messages (

    message_id      BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,

    queue_id        INT UNSIGNED NOT NULL,

    priority        SMALLINT DEFAULT 0,

    handle          CHAR(32),

    md5             CHAR(32) NOT NULL,

    -- millisecond UNIX time when this message was locked
    timeout         BIGINT UNSIGNED,

    created         INT UNSIGNED NOT NULL,

    body            TEXT NOT NULL,

    UNIQUE KEY queue_messages_handle_idx (handle),

    KEY queue_messages_queue_id_idx (queue_id),

    CONSTRAINT queue_messages_queue_id_fkey
        FOREIGN KEY (queue_id) REFERENCES queues (queue_id)
        ON DELETE CASCADE ON UPDATE CASCADE

) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
