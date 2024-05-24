<?php
namespace App\Hedge;

use App\Binance\LimitMakerOrder;
use Binance\Event\Trade;
use Binance\MarginIsolatedApi;
use Laminas\Log\Logger;
use function Binance\truncate;

class UnitaryHedgeBuy extends UnitaryHedge
{
    private bool $ready = false; // when chart has enough data

    public function __construct(
        protected Logger            $log,
        protected MarginIsolatedApi $api,
        protected float             $low,
        protected float             $high
    )
    {
        parent::__construct($log, $api, $low, $high);

        if (0 == truncate($this->account->quoteAsset->free, 2)) {
            throw new \RuntimeException(sprintf('No %s to BUY.', $this->account->quoteAsset->asset));
        }
    }

    public function __invoke(Trade $trade) : void
    {
        parent::__invoke($trade);

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

        // BUY order: if there are no orders or just sold
        if (!isset($this->order) || ($this->order->isSell() && $this->order->isFilled()))
        {
            if ($trade->price > $this->median && $secEMA->now() > $this->median)
            {
                // borrow at first trade and log once
                $this->borrow();

                // API call post order
                $order = new LimitMakerOrder();
                $order->symbol = $this->api->symbol;
                $order->side = 'BUY';
                $order->price = $this->median; // TODO median plus fee diff
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
            if ($trade->price < $this->median
                && $secEMA->now() < $this->median
                && $minEMA->now() < $this->median
                && $minEMA->isDescending(5))
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
