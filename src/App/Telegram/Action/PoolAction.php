<?php

namespace App\Telegram\Action;

use Amqp\Channel;
use App\Telegram\Handler\AbstractHandler;
use App\Telegram\Message\Message;
use App\Telegram\Telegram;
use Longman\TelegramBot\Conversation;
use Longman\TelegramBot\Entities\Keyboard;
use Longman\TelegramBot\Entities\KeyboardButton;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * Collects information from user and send AMQP message to broker.
 */
class PoolAction extends AbstractHandler
{
    protected $name = 'pool';
    protected $description = '/hedge CAKE_AMOUNT LOW HIGH';
    protected $version = '1.1.0';
    protected $need_mysql = true;
    protected $private_only = true;
    protected Conversation $conversation;

    public function __construct(Telegram $telegram,
                                protected Channel $ch)
    {
        parent::__construct($telegram);
    }

    public function execute(): ServerResponse
    {
        $message = $this->getMessage();

        $chat    = $message->getChat();
        $user    = $message->getFrom();
        $text    = trim($message->getText(true));
        $chat_id = $chat->getId();
        $user_id = $user->getId();

        // Preparing response
        $data = new Message($chat);
        $data['text'] = ''; // in this particular script it's only Text Messages replies

        // Recover conversation from db or start new
        $this->conversation = new Conversation($user_id, $chat_id, $this->getName());

        // Load any existing notes from this conversation
        $notes = &$this->conversation->notes;
        !is_array($notes) && $notes = [];

        // Load the current state of the conversation
        $state = $notes['state'] ?? 0;

        $result = Request::emptyResponse();

        // State machine
        // Every time a step is achieved the state is updated
        switch ($state) {
            case 0:
                if ($text === '') {
                    $notes['state'] = 0;
                    $this->conversation->update();

                    $data['text'] = <<<REPLY
I will borrow CAKE for USDT and place order to SELL at high price -25% of range.
If price decrease 25% of price range I will SELL 25% of AMOUNT again.
And so on 2 more trades.
These are margin orders of CAKE SELL against USDT in margin balance.

How much is USDT amount in the Pool?
REPLY;

                    $result = Request::sendMessage((array) $data);
                    break;
                }
                $notes['amount']    = $text;
                $text               = '';

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
                if ($text === '') {
                    $notes['state'] = 3;
                    $this->conversation->update();

                    $data['reply_markup'] = (new Keyboard(
                        [(new KeyboardButton('OK'))],
                        [(new KeyboardButton('Cancel'))],
                    ))
                        ->setOneTimeKeyboard(true)
                        ->setResizeKeyboard(true)
                        ->setSelective(true);

                    $calc = $this->calculateEntry($notes);
                    unset($calc['state']);

                    $reply = &$data['text'];
                    $reply = 'I am going to place these prices (LIMIT/STOP):' . str_repeat(PHP_EOL, 2);
                    foreach ($calc['orders'] as $v) {
                        $reply .= vsprintf('SELL %.2f at %.3f / %.3f', $v);
                        $reply .= PHP_EOL;
                    }
                    $reply .= PHP_EOL . 'Is everything correct?';


                    $result = Request::sendMessage((array) $data);
                    break;
                }

            // No break!
            case 4:
                $this->conversation->update();
                unset($notes['state']);

                if ($text == 'OK') {
                    $this->messageBroker($notes);
                    $data['text']   = 'Request sent.';
                }
                else {
                    $data['text']   = 'Nevermind.';
                }

                $this->conversation->stop();

                $result = Request::sendMessage((array) $data);
                break;
        }

        return $result;
    }

    /**
     * Hedging order to sell on bottom of range.
     * Sells twice amount of CAKE so that when price moves down 50% of range [1],
     *  margin compensate impermanent loss move for the whole range.
     * If price moves down to re-entry rate [1] CAKE in pool is sold by market price,
     * this loss (move from median to low-range*0.5 [1]) is covered by gains in margin.
     * If price moves back into range then buy order is placed to cancel hedging,
     *  and then stop loss order is posted again. Fees are minded and covered by tiny price diff.
     *
     *
     * @return StopOrder
     */
    private function makeSellStopLoss(float $high, float $low, float $amount) : StopOrder
    {
        $median = round(($high + $low) / 2, 3);

        $order = new StopOrder();
        $order->amount = $amount * 2;
        $order->stopPrice = $low;
        $order->price = $this->fee($low, true);

        return $order;
    }

    /**
     * Adjust price so that it will be effective price after binance fee.
     *
     * @param float $price
     * @param bool $up
     * @return float
     */
    private function fee(float $price, bool $up) : float
    {
        $factor = $up ? 1.001 : 0.999;
        return round($price * $factor, 3, $up ? PHP_ROUND_HALF_UP : PHP_ROUND_HALF_DOWN);
    }

    private function calculateEntry(array $input) : array
    {
        $addfee = function (float $price) : float {
            return round($price * 1.001, 3, PHP_ROUND_HALF_UP);
        };

        $range = round($input['high'] - $input['low']);
        $orders = [];

        $parts = 4;
        $step  = round($range / $parts, 3);
        $limit = $input['high'];
        $chunk = round($input['amount'] / $parts, 2);
        do {
            $amount = round($chunk / $addfee($limit), 3, PHP_ROUND_HALF_DOWN);
            $orders[] = [
                $amount,
                // limit price
                $addfee($limit),
                // stop price
                $addfee($limit - $step)
            ];
            $limit -= $step;
        }
        while (--$parts);

        $input['orders'] = $orders;

        return $input;
    }

    private function messageBroker(array $notes) : void
    {
        $message = new \X\Amqp\Message();
        $this->ch->publish($message, 'broker', 'margin.order');

        // TODO amqp request with final order. This chat MUST calculate and send exact numbers to broker.
        usleep(50000);
    }
}
