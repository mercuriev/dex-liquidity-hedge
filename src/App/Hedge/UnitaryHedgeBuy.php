<?php
namespace App\Hedge;

use App\Binance\LimitMakerOrder;
use Binance\Event\Trade;
use function Binance\truncate;

class UnitaryHedgeBuy extends UnitaryHedge
{
    private bool $ready = false; // when chart has enough data

    public function __invoke(Trade $trade, \Bunny\Channel $ch) : void
    {
        parent::__invoke($trade, $ch);

        // rate limit post orders once per second
        if (isset($this->lastPost) && $this->lastPost == time()) return;

        $secEMA = $this->api->s->ema(30);
        $minEMA = $this->api->m->ema(5);

        // BUY order: if there are no orders or just sold
        if (!isset($this->order) || ($this->order->isSell() && $this->order->isFilled()))
        {
            if ($trade->price > $this->high)
            {
                // borrow at first trade and log once
                $this->borrow();

                // API call post order
                $order = new LimitMakerOrder();
                $order->symbol = $this->api->symbol;
                $order->side = 'BUY';
                $order->price = round($this->median * (1 - $this->fee), 2);
                $order->quantity = truncate($this->account->quoteAsset->free / $order->price, $this->precision);
                if ($this->post($order)) {
                    $this->log($this->order);
                }
            }
        }

        // SELL order: if we sold and there is trend reverse
        if (isset($this->order) && $this->order->isBuy() && $this->order->isFilled())
        {
            // price is rising and above median
            if ($trade->price < $this->low)
            {
                $flip = $this->flip($this->order);
                if ($this->post($flip)) {
                    $this->log($this->order);
                }
            }
        }
    }

    protected function getBorrowAsset(): string
    {
        return $this->account->quoteAsset->asset;
    }

    protected function getTotalQuoteValue(): float
    {
        $median = round(($this->low + $this->high) / 2);
        $value = $this->account->quoteAsset->free;
        $value += $this->account->baseAsset->free * $median;
        if ($this->account->marginLevel == 999) {
            $asset = $this->getBorrowAsset();
            $value += $this->api->maxBorrowable($asset);
        }
        return $value;
    }
}
