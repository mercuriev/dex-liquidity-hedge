<?php

namespace App\Hedge;

use Binance\Event\Trade;
use Binance\Exception\BinanceException;
use Binance\MarginIsolatedApi;
use App\Binance\LimitMakerOrder;
use Laminas\Log\Logger;
use function Binance\truncate;

class UnitaryHedgeSell extends UnitaryHedge
{
    private bool $ready = false;

    public function __construct(
        protected Logger            $log,
        protected MarginIsolatedApi $api,
        protected string            $symbol,
        protected float             $low,
        protected float $high
    )
    {
        parent::__construct($log, $api, $symbol, $low, $high);

        if (0 == truncate($this->account->baseAsset->free, $this->precision)) {
            throw new \RuntimeException(sprintf('No %s to SELL.', $this->account->baseAsset->asset));
        }
    }

    public function __invoke(Trade $trade) : void
    {
        parent::__invoke($trade);

        try {
            $secema = $this->sec->ema(10);
            $minema  = $this->min->ema(5);
            if (!$this->ready) {
                $this->log->info('Collected enough graph info to build EMA.');
                $this->ready = true;
            }
        } catch (\UnderflowException) {
            return;
        }

        // check if trade triggered our order
        if (isset($this->order) && !$this->order->isFilled()) {
            $this->order->match($trade);
            if ($this->order->isFilled()) {
                $this->log($this->order);
                $this->filled(); // hook
            }
        }

        // rate limit post orders once per second
        if (isset($this->lastPost) && $this->lastPost == time()) return;

        // TODO if minute EMA(10) is below median and sell is not filled enter emergency mode and update order to EMA(10) price every minute. capped by min/max?
        // TODO same as above for buy orders, emergency/fallback mode
        // TODO account fees and shift sell/buy orders up or down to pay for the fee

        // TODO post sell if price is lower than median (or min? or?), and we didn't sell yet
        if ($trade->price < $this->median && $secema->now() < $this->median) {
            if (!isset($this->order) || ($this->order->isBuy() && $this->order->isFilled())) {
                // borrow at first trade and log once
                if ($this->account->marginLevel == 999) {
                    $borrowed = $this->borrow();
                    $this->log->info(sprintf('Borrowed %.5f %s', $borrowed, $this->getBorrowAsset()));
                } else if (!isset($this->lastPost)) {
                    $this->log->info('Skip borrow. Margin level: ' . $this->account->marginLevel);
                }

                $order = new LimitMakerOrder();
                $order->symbol = $this->symbol;
                $order->side = 'SELL';
                $order->quantity = truncate($this->account->baseAsset->free, $this->precision);
                $order->price = $this->median; // TODO median plus fee diff
                if ($this->post($order)) {
                    $this->log($this->order);
                }
            }
        }

        // TODO post buy if we sold and price is higher than median (or max? or?)
        if (isset($this->order) && $this->order->isSell() && $this->order->isFilled()) {
            if ($trade->price > $this->median && $minema->now() > $this->median && $minema->isAscending(5)) {
                $flip = $this->flip($this->order);
                if ($this->post($flip)) {
                    $this->log($this->order);
                }
            }
        }
    }

    /** @noinspection PhpFieldAssignmentTypeMismatchInspection */
    protected function post(LimitMakerOrder $order) : ?LimitMakerOrder
    {
        try {
            return $this->order = $this->api->post($order);
        }
        catch (BinanceException $e) {
            // Try on next call if: Order would immediately match and take.
            if ($e->getCode() != -2010)
                throw $e;
        }
        finally {
            $this->lastPost = time();
        }
        return null;
    }

    protected function getBorrowAsset(): string
    {
        return $this->account->baseAsset->asset;
    }

    // TODO for hedge buy calc with borrowable quote asset
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

    protected function filled() : void
    {
    }

    private function flip(LimitMakerOrder $order) : LimitMakerOrder
    {
        $flip = new LimitMakerOrder();
        $flip->symbol = $this->symbol;
        $flip->side = $order->side == 'BUY' ? 'SELL' : 'BUY';
        $flip->price = $order->price;
        $flip->quantity = $order->quantity;
        return $flip;
    }
}
