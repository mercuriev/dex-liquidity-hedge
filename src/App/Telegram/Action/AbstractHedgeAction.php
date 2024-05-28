<?php

namespace App\Telegram\Action;

use App\Telegram\Handler\AbstractHandler;
use Longman\TelegramBot\Conversation;
use Longman\TelegramBot\Entities\InlineKeyboard;
use Longman\TelegramBot\Entities\InlineKeyboardButton;
use Longman\TelegramBot\Entities\Keyboard;
use Longman\TelegramBot\Entities\KeyboardButton;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

abstract class AbstractHedgeAction extends AbstractHandler
{
    protected Conversation $conversation;

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
        $this->conversation = new Conversation($user_id, $chat_id, $this->getName());

        // Load any existing notes from this conversation
        $notes = &$this->conversation->notes;
        !is_array($notes) && $notes = [];
        $state = $notes['state'] ?? 0;

        $result = Request::emptyResponse();

        // State machine
        // Every time a step is achieved the state is updated
        switch ($state) {
            // No break!
            case 0:
                if ($text === '') {
                    $notes['state'] = 0;
                    $this->conversation->update();

                    $data['text'] = 'Alright! I will ' . $this->getName() . '. Let me know range.';
                    $data['reply_markup'] = new InlineKeyboard([
                        new InlineKeyboardButton([
                            'text'          => '<< Cancel',
                            'callback_data' => 'cancel'
                        ]),
                    ]);
                    $result = Request::sendMessage((array) $data);

                    $data['text'] = 'Symbol?';
                    $result = Request::sendMessage((array) $data);
                    break;
                }
                $notes['symbol']   = $text;
                $text           = '';

            // No break!
            case 1:
                if ($text === '') {
                    $notes['state'] = 1;
                    $this->conversation->update();

                    $data['text'] = 'Low price?';

                    $result = Request::sendMessage((array) $data);
                    break;
                }
                $notes['low']   = $text;
                $text           = '';

            // No break!
            case 2:
                if ($text === '' || !is_numeric($text)) {
                    $notes['state'] = 2;
                    $this->conversation->update();

                    $data['text'] = 'High price?';
                    if ($text !== '') {
                        $data['text'] = 'Must be a number';
                    }

                    $result = Request::sendMessage((array) $data);
                    break;
                }

                $notes['high'] = $text;
                $text          = '';

            // No break!
            case 3:
                $this->conversation->update();
                unset($notes['state']);

                $this->publish($notes);
                $data['text']   = 'Request sent.';

                $this->conversation->stop();

                $result = Request::sendMessage((array) $data);
                break;
        }

        return $result;
    }

    protected function publish(array $notes): bool|int
    {
        return $this->ch->bunny->publish(implode(' ', $notes), 'hedge', $this->getName());
    }
}
