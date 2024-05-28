<?php

namespace App\Telegram\Conversation;

use Longman\TelegramBot\Entities\InlineKeyboard;
use Longman\TelegramBot\Entities\InlineKeyboardButton;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class HedgeConversation extends AbstractConversation
{
    public function run(string $text) : ServerResponse
    {
        if (empty($this->notes)) {
            $this->intro();
        }

        if (!isset($this->notes['symbol'])) {
            if ($text) {
                $this->notes['symbol'] = $text;
                $text = '';
                $this->update();
            } else {
                return $this->askSymbol();
            }
        }

        if (!isset($this->notes['low'])) {
            if ($text) {
                $this->notes['low'] = $text;
                $text = '';
                $this->update();
            } else {
                return $this->askLow();
            }
        }

        if (!isset($this->notes['high'])) {
            if ($text) {
                $this->notes['high'] = $text;
                $text = '';
                $this->update();
            } else {
                return $this->askHigh();
            }
        }

        $this->stop();

        return Request::sendMessage(['chat_id' => $this->chat_id, 'text' => 'Done']);
    }

    public function intro() : ServerResponse
    {
        $data['chat_id'] = $this->chat_id;
        $data['text'] = "Alright! I will $this->command. Let me know range.";
        $data['reply_markup'] = new InlineKeyboard([
            new InlineKeyboardButton([
                'text'          => '<< Cancel',
                'callback_data' => 'cancel'
            ]),
        ]);
        return Request::sendMessage((array) $data);
    }

    public function askSymbol()
    {
        return $this->ask('Symbol?');
    }

    public function askLow()
    {
        return $this->ask('Low?');
    }

    public function askHigh()
    {
        return $this->ask('High?');
    }
}
