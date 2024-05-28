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

    public function message($text, array $extra = []) : ServerResponse
    {
        return Request::sendMessage(array_merge([
            'chat_id' => $this->chat_id,
            'text' => $text,
        ], $extra));
    }
}
