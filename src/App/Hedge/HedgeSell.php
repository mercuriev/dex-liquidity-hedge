<?php
namespace App\Hedge;

use Binance\Exception\BinanceException;
use Binance\Exception\InsuficcientBalance;
use Binance\Exception\InvalidPrices;
use Binance\Exception\StopPriceTrigger;
use Binance\Order\LimitOrder;
use Binance\Order\StopOrder;
use function Binance\truncate;

class HedgeSell extends Hedge
{
    protected function getBorrowAsset(): string
    {
        return $this->account->baseAsset->asset;
    }

    /**
     * @throws InsuficcientBalance
     * @throws BinanceException
     * @throws StopPriceTrigger
     * @throws InvalidPrices
     */
    protected function new(int $index): null|StopOrder|LimitOrder
    {
        $amount = truncate($this->account->baseAsset->free, $this->precision);
        $amount = truncate($amount / (count($this)), $this->precision);

        $new = new StopOrder();
        $new->symbol = $this->symbol;
        $new->side = 'SELL';
        $new->quantity = $amount;
        $new->setPrice($this->prices[$index]);

        return $this[$index] = $this->post($new);
    }

    /**
     * @throws InsuficcientBalance
     * @throws BinanceException
     * @throws StopPriceTrigger
     * @throws InvalidPrices
     */
    protected function filled(int $index): null|StopOrder|LimitOrder
    {
        $up = $index;
        while($this->offsetExists(--$up)) {
            $prev = $this[$up];
            if ($prev->isFilled()) {
                $flip = new StopOrder();
                $flip->symbol = $this->symbol;
                $flip->side = 'BUY';
                $flip->price = $prev->price;
                if ($flip instanceof StopOrder) {
                    $flip->stopPrice = $flip->price;
                }
                $flip->quantity = $prev->quantity;
                $this[$up] = $this->post($flip);
                $this->log($up);
            }
        }

        $down = $index;
        while ($this->offsetExists(++$down)) {
            $next = $this[$down];
            if ($next->isFilled()) {
                $this->new($down);
                $this->log($down);
            }
        }

        return null;
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
        return $order;
    }
}
