<?php
namespace App\Hedge;

use Binance\Exception\BinanceException;
use Binance\Exception\InsuficcientBalance;
use Binance\Exception\InvalidPrices;
use Binance\Exception\StopPriceTrigger;
use Binance\Order\AbstractOrder;
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
        // FIXME try to prevent taker trades by setting stopPrice 1/10 step lower for SELL
        // TODO watch for the price level and post LIMIT_MAKER FOK until filled

        $up = $index;
        while($this->offsetExists(--$up)) {
            $prev = $this[$up];
            if ('SELL' == $prev->side && $prev->isFilled()) {
                $flip = $this->flip($prev);
                $this[$up] = $this->post($flip);
                $this->log($up);
            }
        }

        // highest order
        if ($index == 0 && 'BUY' == $this[$index]->side) {
            $flip = $this->flip($this[$index]);
            $this[$index] = $this->post($flip);
            $this->log($index);
        }

        $down = $index;
        while ($this->offsetExists(++$down)) {
            $next = $this[$down];
            if ('BUY' == $next->side && $next->isFilled()) {
                $this->new($down);
                $this->log($down);
            }
        }

        // lowest order
        if ($index == count($this) - 1 && 'SELL' == $this[$index]->side) {
            $flip = $this->flip($this[$index]);
            $this[$index] = $this->post($flip);
            $this->log($index);
        }

        return null;
    }

    private function flip(AbstractOrder $order) : AbstractOrder
    {
        $flip = new StopOrder();
        $flip->symbol = $this->symbol;
        $flip->side = $order->side == 'BUY' ? 'SELL' : 'BUY';
        $flip->price = $order->price;
        if ($flip instanceof StopOrder) {
            $flip->stopPrice = $flip->price;
        }
        $flip->quantity = $order->quantity;
        return $flip;
    }
}
