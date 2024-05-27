<?php

namespace App\Telegram\Handler;

use App\Telegram\Telegram;

abstract class AbstractHandler extends \Longman\TelegramBot\Commands\UserCommand
{
    /**
     * Overload to type-hint App's class for service manager.
     */
    public function __construct(Telegram $telegram)
    {
        parent::__construct($telegram);
    }
}
