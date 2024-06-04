<?php

namespace App\Telegram\Message;

use Longman\TelegramBot\Entities\Chat;
use Longman\TelegramBot\Entities\Keyboard;

class Message extends \ArrayObject
{
    public function __construct(Chat $chat)
    {
        $data = [
            'chat_id'       => $chat->getId(),
            // Remove any keyboard by default
            'reply_markup' => Keyboard::remove(['selective' => true]),
        ];

        if ($chat->isGroupChat() || $chat->isSuperGroup()) {
            // Force reply is applied by default so it can work with privacy on
            $data['reply_markup'] = Keyboard::forceReply(['selective' => true]);
        }

        parent::__construct($data);
    }
}
