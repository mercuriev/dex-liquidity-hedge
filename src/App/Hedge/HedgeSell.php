<?php
namespace App\Hedge;

use Binance\Order\LimitOrder;
use Binance\Order\StopOrder;

class HedgeSell extends Hedge
{
    protected function do(int $index): StopOrder
    {
        // +1 because lowest step doesn't have an open order
        $amount = round($this->amount / (count($this) + 1), 5, PHP_ROUND_HALF_DOWN);
        $order = new StopOrder();
        $order->newOrderRespType = 'FULL';  // important for offline matching
        $order->symbol = $this->symbol;
        $order->side = 'SELL';
        $order->quantity = $amount;
        $order->setPrice($this->range[$index]);
        $order->stopPrice = $order->price - $this->step;
        return $order;
    }

    protected function undo(int $index): StopOrder|LimitOrder
    {
        $order = $this[$index];
        $class = get_class($order);
        $mirror = new $class;
        $mirror->side = 'BUY';
        $mirror->price = $order->price;
        if ($order instanceof StopOrder) {
            $mirror->newOrderRespType = 'FULL';  // important for offline matching
            $mirror->stopPrice = $mirror->price;
            $mirror->stopPrice += $this->step; // delay buyback (only if price is back up)
        }
        $mirror->quantity = $order->quantity;
        return $mirror;
    }
}
