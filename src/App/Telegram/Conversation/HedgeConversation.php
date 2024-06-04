<?php

namespace App\Telegram\Conversation;

use Bunny\Channel;
use Longman\TelegramBot\Entities\InlineKeyboard;
use Longman\TelegramBot\Entities\InlineKeyboardButton;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class HedgeConversation extends AbstractConversation
{
    public Channel $channel;

    protected $callbackComplete;

    private function status() : string
    {
        $text = "I will $this->command";
        if (@$symbol = $this->notes['symbol']) {
            $text .= " in $symbol";
        }
        if (@$low = $this->notes['low']) {
            $text .= " between low $low";
        }
        if (@$high = $this->notes['high']) {
            $text .= " and high $high.\n";
            $median = round(($low + $high) / 2, 2);
            $text .= "Median: $median";
        }
        $text .= '.';
        return $text;
    }

    public function run(string $text) : ServerResponse
    {
        $inline = [
            'reply_markup' => new InlineKeyboard([
                new InlineKeyboardButton([
                    'text'          => '<< Cancel',
                    'callback_data' => 'cancel'
                ]),
            ])
        ];

        if (!isset($this->notes['symbol'])) {
            if ($text) {
                $this->notes['symbol'] = strtoupper($text);
                $text = '';
                $this->update();
            } else {
                $reply = $this->status() . "\nSymbol?";
                return $this->message($reply, $inline);
            }
        }

        if (!isset($this->notes['low'])) {
            if ($text) {
                $this->notes['low'] = $text;
                $text = '';
                $this->update();
            } else {
                $reply = $this->status() . "\nLow?";
                return $this->message($reply, $inline);
            }
        }

        if (!isset($this->notes['high'])) {
            if ($text) {
                $this->notes['high'] = $text;
                $text = '';
                $this->update();
            } else {
                $reply = $this->status() . "\nHigh?";
                return $this->message($reply, $inline);
            }
        }

        if (!isset($this->notes['confirm'])) {
            if ($text) {
                $this->notes['confirm'] = $text;
                $text = '';
                $this->update();
            } else {
                $reply = $this->status() . "\nConfirm? Any message to proceed.";
                return $this->message($reply, $inline);
            }
        }

        if ($this->callbackComplete) {
            $reply = call_user_func($this->callbackComplete, $this->notes);
        }
        else $reply = 'No callback!';

        // this clears notes
        $this->stop();

        if ($reply) {
            return Request::sendMessage(['chat_id' => $this->chat_id, 'text' => $reply]);
        } else {
            return Request::emptyResponse();
        }
    }

    public function onComplete(callable $callback) : self
    {
        $this->callbackComplete = $callback;
        return $this;
    }
}
