<?php

namespace App\Telegram\Action;

use App\Telegram\Handler\AbstractHandler;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class CancelAction extends AbstractHandler
{
    protected $name = 'cancel';

    /**
     * @inheritDoc
     */
    public function execute(): ServerResponse
    {
        // FIXME require symbol
        $this->ch->bunny->publish('', 'hedge', 'cancel');
        return Request::emptyResponse();
    }
}
