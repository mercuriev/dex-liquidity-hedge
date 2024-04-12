<?php
namespace App\Hedge;

use Binance\Order\LimitOrder;
use Binance\Order\StopOrder;

class HedgeSell extends Hedge
{
    protected function trigger(int $index): StopOrder|LimitOrder
    {
        $order = $this[$index];

        if (!$order || 'BUY' == $order->side) {
            // +1 because lowest step doesn't have an open order
            $amount = round($this->amount / (count($this) + 1), 5, PHP_ROUND_HALF_DOWN);
            $new = new StopOrder();
            $new->newOrderRespType = 'FULL';  // important for offline matching
            $new->symbol = $this->symbol;
            $new->side = 'SELL';
            $new->quantity = $amount;
            $new->setPrice($this->range[$index]);
            #$new->stopPrice = $new->price - $this->step;
        } else {
            $new = new StopOrder();
            $new->side = 'BUY';
            $new->price = $order->price;
            if ($new instanceof StopOrder) {
                $new->newOrderRespType = 'FULL';  // important for offline matching
                $new->stopPrice = $new->price;
                $new->stopPrice += $this->step; // delay buyback (only if price is back up)
            }
            $new->quantity = $order->quantity;
        }
        return $new;
    }
}
