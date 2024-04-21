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
        foreach ($this as $i => $order) {
            if ($i == $index) continue; // avoid quick buy/sell
            if ('SELL' == $order->side && $order->isFilled()) {
                $flip = new StopOrder();
                $flip->symbol = $this->symbol;
                $flip->side = 'BUY';
                $flip->quantity = $order->quantity;
                $flip->price = $order->price;
                if ($flip instanceof StopOrder) {
                    $flip->stopPrice = $flip->price;
                }

                $this[$i] = $this->post($flip);
                $this->log($i);
            }

            if ('BUY' == $order->side && $order->isFilled()) {
                $this->new($i);
                $this->log($i);
            }
        }
        return null;
    }
}
