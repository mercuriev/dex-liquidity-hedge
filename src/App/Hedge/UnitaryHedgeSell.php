<?php
namespace App\Hedge;

use App\Binance\LimitMakerOrder;
use Binance\Event\Trade;
use Bunny\Channel;
use function Binance\truncate;

class UnitaryHedgeSell extends UnitaryHedge
{
    public function __invoke(Trade $trade, Channel $ch) : void
    {
        parent::__invoke($trade, $ch);

        // rate limit post orders once per second
        if (isset($this->lastPost) && $this->lastPost == time()) return;

        // TODO if minute EMA(10) is below median and sell is not filled enter emergency mode and update order to EMA(10) price every minute. capped by min/max?
        // TODO same as above for buy orders, emergency/fallback mode

        $secEMA = $this->api->s->ema(30);
        $minEMA = $this->api->m->ema(5);

        // SELL order: if there are no orders or just bought
        if (!isset($this->order) || ($this->order->isBuy() && $this->order->isFilled()))
        {
            if ($trade->price < $this->median
                && $secEMA->now() < $this->median
                && $secEMA->isDescending(10, 0.8)
                && $minEMA->now() < $this->median
                && $minEMA->isDescending(5, 0.6)
            )
            {
                // borrow at first trade and log once
                $this->borrow();

                // API call post order
                $order = new LimitMakerOrder();
                $order->symbol = $this->api->symbol;
                $order->side = 'SELL';
                $order->quantity = truncate($this->account->baseAsset->free, $this->precision);
                $order->price = round($this->median * (1 + $this->fee), 2);
                if ($this->post($order)) {
                    $this->log($this->order);
                }
            }
        }

        // BUY order: if we sold and there is trend reverse
        if (isset($this->order) && $this->order->isSell() && $this->order->isFilled())
        {
            // price is rising and above median
            if ($trade->price > $this->median
                && $secEMA->now() > $this->median
                && $secEMA->isAscending(10, 0.8)
                && $minEMA->now() > $this->median
                && $minEMA->isAscending(5, 0.6)
            )
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
        return $this->account->baseAsset->asset;
    }

    protected function getTotalQuoteValue(): float
    {
        $median = round(($this->low + $this->high) / 2);
        $value = $this->account->quoteAsset->free;
        $value += $this->account->baseAsset->free * $median;
        if ($this->account->marginLevel == 999) {
            $asset = $this->getBorrowAsset();
            $borrowable = $this->api->maxBorrowable($asset);
            $value += $borrowable * $median;
        }
        return $value;
    }
}
