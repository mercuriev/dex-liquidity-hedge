<?php

namespace App\Logger;

use Amqp\Channel;
use Laminas\Log\Writer\AbstractWriter;

class AmqpWriter extends AbstractWriter
{
    private bool $declared = false;

    /** @noinspection PhpMissingParentConstructorInspection */
    public function __construct(protected Channel $channel) {}

    /**
     * @inheritDoc
     */
    protected function doWrite(array $event): void
    {
        if (!$this->declared) {
            // exchange must not be declared in construct to not call Channel lazy service in Logger factory
            $this->channel->exchangeDeclare('log', type: 'topic');
            $this->declared = true;
        }
        $this->channel->bunny->publish($event['message'], 'log', strtolower($event['priorityName']));
    }
}
