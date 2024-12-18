<?php

namespace App\Telegram\Callback;

use Longman\TelegramBot\Conversation;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class CancelCallbackHandler extends AbstractCallbackHandler
{
    protected array $callbacks = ['cancel'];
    public function run(CallbackQuery $query) : ?ServerResponse
    {
        $user = $query->getFrom();
        $chat = $query->getFrom()->getId();

        $convo = new Conversation($user->getId(), $chat);
        $convo->cancel();

        Request::sendMessage(['chat_id' => $chat, 'text' => 'Canceled.']);

        return $query->answer();
    }
}
