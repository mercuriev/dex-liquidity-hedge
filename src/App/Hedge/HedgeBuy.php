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
        $amount = truncate($this->account->quoteAsset->free, $this->precision);
        $amount = truncate($amount / (count($this)), $this->precision);

        $new = new StopOrder();
        $new->symbol = $this->symbol;
        $new->side = 'BUY';
        $new->quoteOrderQty = $amount;
        $new->setPrice($this->prices[$index]);

        if (($index + 1) == $this->count()) {
            $new->stopPrice += $this->step;
        }

        $this[$index] = $this->post($new);

        return $this[$index];
    }

    protected function filled(int $index): ?AbstractOrder
    {
        $up = $index;
        while($this->offsetExists(--$up)) {
            $prev = $this[$up];
            if ($prev->isFilled()) {
                $flip = new StopOrder();
                $flip->symbol = $this->symbol;
                $flip->side = 'SELL';
                $flip->quoteOrderQty = $prev->quoteOrderQty;
                $flip->price = $prev->price;
                if ($flip instanceof StopOrder) {
                    $flip->stopPrice = $flip->price;
                }

                $this[$up] = $this->post($flip);
                $this->log($up);
            }
        }

        $down = $index;
        while ($this->offsetExists(++$down)) {
            $next = $this[$down];
            if ('SELL' == $next->side && $next->isFilled()) {
                $this->new($down);
                $this->log($down);
            }
        }
        return null;
    }
}
