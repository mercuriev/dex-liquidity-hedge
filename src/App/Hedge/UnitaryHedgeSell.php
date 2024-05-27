<?php
namespace App\Hedge;

use App\Binance\LimitMakerOrder;
use Binance\Event\Trade;
use Binance\MarginIsolatedApi;
use Bunny\Channel;
use Bunny\Message;
use Laminas\Log\Logger;
use function Binance\truncate;

class UnitaryHedgeSell extends UnitaryHedge
{
    private bool $ready = false; // when chart has enough data

    public function __invoke(Trade $trade, Channel $ch) : void
    {
        parent::__invoke($trade, $ch);

        // collect enough data to build technical analysis
        try {
            $secEMA  = $this->sec->ema(10);
            $minEMA  = $this->min->ema(5);
            if (!$this->ready) {
                $this->log->info('Collected enough graph info to build EMA.');
                $this->ready = true;
            }
        } catch (\UnderflowException) {
            return;
        }

        // rate limit post orders once per second
        if (isset($this->lastPost) && $this->lastPost == time()) return;

        // TODO if minute EMA(10) is below median and sell is not filled enter emergency mode and update order to EMA(10) price every minute. capped by min/max?
        // TODO same as above for buy orders, emergency/fallback mode
        // TODO account fees and shift sell/buy orders up or down to pay for the fee

        // SELL order: if there are no orders or just bought
        if (!isset($this->order) || ($this->order->isBuy() && $this->order->isFilled()))
        {
            if ($trade->price < $this->median && $secEMA->now() < $this->median)
            {
                // borrow at first trade and log once
                $this->borrow();

                // API call post order
                $order = new LimitMakerOrder();
                $order->symbol = $this->api->symbol;
                $order->side = 'SELL';
                $order->quantity = truncate($this->account->baseAsset->free, $this->precision);
                $order->price = $this->median; // TODO median plus fee diff
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
                && $minEMA->now() > $this->median
                && $minEMA->isAscending(5))
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
