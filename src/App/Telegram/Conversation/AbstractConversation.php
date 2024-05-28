<?php

namespace App\Telegram\Conversation;

use Longman\TelegramBot\Conversation;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

abstract class AbstractConversation extends Conversation
{
    public $notes = [];
    public int $state = 0;

    abstract public function run(string $text);

    public function ask($question) : ServerResponse
    {
        return Request::sendMessage([
            'chat_id' => $this->chat_id,
            'text' => $question,
        ]);
    }
}
