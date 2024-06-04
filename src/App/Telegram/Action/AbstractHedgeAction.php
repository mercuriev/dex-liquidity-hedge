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
        $conversation->onComplete([$this, 'publish']);
        return $conversation->run($text);
    }

    public function publish(array $notes): string
    {
        $symbol = strtoupper($notes['symbol']);
        $this->ch->bunny->publish(
            $notes['low'].' '.$notes['high'],
            'hedge',
            "$symbol.".$this->getName()
        );
        return '';
    }
}
