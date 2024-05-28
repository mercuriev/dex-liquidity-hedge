<?php

namespace App\Telegram\Action;

use App\Telegram\Conversation\HedgeConversation;
use App\Telegram\Handler\AbstractHandler;
use Longman\TelegramBot\Conversation;
use Longman\TelegramBot\Entities\ServerResponse;

abstract class AbstractHedgeAction extends AbstractHandler
{
    /**
     * @inheritDoc
     */
    public function execute(): ServerResponse
    {
        $message = $this->getMessage();

        $chat    = $message->getChat();
        $user    = $message->getFrom();
        $text    = trim($message->getText(true));
        $chat_id = $chat->getId();
        $user_id = $user->getId();

        // Recover conversation from db or start new
        $conversation = new HedgeConversation($user_id, $chat_id, $this->getName());
        return $conversation->run($text);
    }

    protected function publish(array $notes): bool|int
    {
        return $this->ch->bunny->publish(implode(' ', $notes), 'hedge', $this->getName());
    }
}
