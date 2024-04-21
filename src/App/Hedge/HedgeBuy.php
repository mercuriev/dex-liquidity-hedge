<?php

namespace App\Hedge;

use Binance\Order\AbstractOrder;
use Binance\Order\StopOrder;
use function Binance\truncate;

class HedgeBuy extends Hedge
{
    protected function getBorrowAsset(): string
    {
        return $this->account->quoteAsset->asset;
    }

    protected function new(int $index): ?AbstractOrder
    {
        $amount = $this->account->quoteAsset->free / (count($this));

        $new = new StopOrder();
        $new->symbol = $this->symbol;
        $new->side = 'BUY';
        $new->setPrice($this->prices[$index]);
        $new->quantity = truncate($amount / $new->price, $this->precision);

        $this[$index] = $this->post($new);

        return $this[$index];
    }

    protected function filled(int $index): ?AbstractOrder
    {
        foreach ($this as $i => $order) {
            if ($i == $index) continue; // avoid quick buy/sell
            if ('BUY' == $order->side && $order->isFilled()) {
                $flip = new StopOrder();
                $flip->symbol = $this->symbol;
                $flip->side = 'SELL';
                $flip->quantity = $order->quantity;
                $flip->price = $order->price;
                if ($flip instanceof StopOrder) {
                    $flip->stopPrice = $flip->price;
                }

                $this[$i] = $this->post($flip);
                $this->log($i);
            }

            if ('SELL' == $order->side && $order->isFilled()) {
                $this->new($i);
                $this->log($i);
            }
        }
        return null;
    }
}
