<?php
namespace App\Hedge;

use Binance\Exception\BinanceException;
use Binance\Exception\InsuficcientBalance;
use Binance\Exception\StopPriceTrigger;
use Binance\Order\LimitOrder;
use Binance\Order\StopOrder;

class HedgeSell extends Hedge
{
    protected function new(int $index): null|StopOrder|LimitOrder
    {
        $amount = round($this->amount / (count($this)), 5, PHP_ROUND_HALF_DOWN);
        $new = new StopOrder();
        $new->newOrderRespType = 'FULL';  // important for offline matching
        $new->symbol = $this->symbol;
        $new->side = 'SELL';
        $new->quantity = $amount;
        $new->setPrice($this->range[$index]);

        $this[$index] = $this->post($new);

        return $this[$index];
    }

    protected function filled(int $index): null|StopOrder|LimitOrder
    {
        $up = $index;
        while($this->offsetExists(--$up)) {
            $prev = $this[$up];
            if ($prev->isFilled()) {
                $flip = $this->flip($prev);
                $this[$up] = $this->post($flip);
                // FIXME
                $this->log($up);
            }
        }

        $down = $index;
        while ($this->offsetExists(++$down)) {
            $next = $this[$down];
            if ($next->isFilled()) {
                $this->new($down);
                // FIXME
                $this->log($down);
            }
        }

        return null;
    }

    private function flip($order)
    {
        $new = new StopOrder();
        $new->side = 'BUY';
        $new->price = $order->price;
        if ($new instanceof StopOrder) {
            $new->newOrderRespType = 'FULL';  // important for offline matching
            $new->stopPrice = $new->price;
        }
        $new->quantity = $order->quantity;

        return $new;
    }

    /**
     * If stop price is rejected post LIMIT order instead.
     *
     * @param StopOrder $order
     * @return StopOrder|LimitOrder
     * @throws BinanceException
     * @throws StopPriceTrigger
     * @throws \Binance\Exception\InsuficcientBalance
     * @throws \Binance\Exception\InvalidPrices
     */
    private function post(StopOrder|LimitOrder $order) : StopOrder|LimitOrder
    {
        // TODO autoRepayAtCancel = FALSE (so that we not pay 1hr interest each order)
        try {
            $order = $this->api->post($order);
        }
        catch (StopPriceTrigger) { // current price is lower
            $limit = new LimitOrder();
            $limit->symbol = $this->symbol;
            $limit->side = 'SELL';
            $limit->price = $order->price;
            $limit->quantity = $order->quantity;
            $order = $this->api->post($limit);
        }
        catch (InsuficcientBalance $e) {
            xdebug_break();
        }
        return $order;
    }
}
