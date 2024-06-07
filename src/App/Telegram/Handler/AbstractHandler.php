<?php

namespace App\Telegram\Handler;

use Amqp\Channel;
use App\Telegram\Telegram;
use Longman\TelegramBot\Commands\UserCommand;

abstract class AbstractHandler extends UserCommand
{
    /**
     * Overload to type-hint App's class for service manager.
     */
    public function __construct(Telegram $telegram, protected Channel $ch)
    {
        parent::__construct($telegram);
    }
}
