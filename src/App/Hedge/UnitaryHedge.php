<?php

namespace App\Hedge;

use Binance\Account\MarginIsolatedAccount;
use Binance\Chart\Minutes;
use Binance\Chart\Seconds;
use Binance\Entity\ExchangeInfo;
use Binance\Event\Trade;
use Binance\Exception\BinanceException;
use Binance\Exception\ExceedBorrowable;
use Binance\MarginIsolatedApi;
use Binance\MarketDataApi;
use Binance\Order\AbstractOrder;
use Binance\Order\LimitMakerOrder;
use Laminas\Log\Logger;

abstract class UnitaryHedge
{
    protected ExchangeInfo $info;
    protected MarginIsolatedAccount $account;
    protected float $median;
    protected int $precision;

    protected LimitMakerOrder $order;
    protected int $lastPost;
    protected Seconds $sec;
    protected Minutes $min;

    abstract protected function getBorrowAsset() : string;
    protected function filled() {}
    protected function above(float $price) {}
    protected function below(float $price) {}

    public function __construct(
        protected Logger            $log,
        protected MarginIsolatedApi $api,
        protected string            $symbol,
        protected float             $low,
        protected float             $high
    )
    {
        $this->api->symbol = $this->symbol;

        $this->median = round(($low + $high) / 2);
        $this->log->info(sprintf(
            '%s Range: %.2f - %.2f (%.2f%%) / Median: %.2f',
            $symbol,
            $low, $high, (($high - $low) / $high * 100),
            $this->median,
        ));

        // TODO borrow on the first trade only
        $this->account = $this->api->getAccount($this->symbol);
        if ($this->account->marginLevel == 999) {
            $this->borrow();
        } else {
            $this->log->info('Skip borrow. Margin level: ' . $this->account->marginLevel);
        }
        $msg = sprintf('%s: %.5f (%.5f). %s: %.2f (%.2f)',
            $this->account->baseAsset->asset, $this->account->baseAsset->free, $this->account->baseAsset->borrowed,
            $this->account->quoteAsset->asset, $this->account->quoteAsset->free, $this->account->quoteAsset->borrowed,
        );
        $this->log->info($msg);

        // precision is used to place orders, required for crypto-to-crypto pairs
        $this->info = $this->api->exchangeInfo();
        $step = $this->info->getFilter($this->symbol, 'LOT_SIZE')['stepSize'];
        $this->precision = strlen($step) - strlen(ltrim($step, '0.')) - 1;

        $this->sec = new Seconds();
        $this->min = new Minutes();
        $klines = (new MarketDataApi([]))->getKlines([
            'symbol' => $symbol,
            'interval' => '1m',
            'startTime' => (time() - 360) * 1000, // last 6 minutes enough for EMA(5)
            'endTime' => time() * 1000
        ]);
        if ($klines) {
            $this->min->withKlines($klines);
        }
        else throw new \RuntimeException('Failed to load minutes klines.');
    }

    /**
     * Feed Trade event from exchange so that this can match existing orders and post new.
     */
    public function __invoke(Trade $trade) : void
    {
        $this->sec->append($trade);
        $this->min->append($trade);
    }

    /**
     * @throws ExceedBorrowable
     * @throws BinanceException
     */
    protected function borrow(): void
    {
        $asset = $this->getBorrowAsset();

        try {
            $max = $this->api->maxBorrowable($asset);
        }
        catch (BinanceException $e) {
            if (-3045 == $e->getCode()) { // "The system does not have enough asset now."
                $this->log->err("Binance pool of $asset is empty. Try later.");
            }
            throw $e;
        }

        try {
            $this->api->borrow($asset, $max);
            $this->account = $this->api->getAccount($this->symbol);
        }
        catch(ExceedBorrowable $e) {
            $max = $this->api->maxBorrowable($asset);
            $this->log->err("Unable to borrow $max $asset. Exceed limit.");
            throw $e;
        }
    }

    protected function log(AbstractOrder $order) : void
    {
        if ($order->isFilled()) {
            $msg = '▶';
            $status = 'SELL' == $order->side ? 'SOLD' : 'BGHT';
        }
        else {
            $msg = '▷';
            $status = $order->side;
        }
        $msg .= sprintf(
            '%-4s %.2f',
            $status,
            $order->price
        );
        $msg .= (isset($order->stopPrice) ? sprintf(' @ %.2f', $order->stopPrice) : '');

        $msg .= ' ('.round($order->quantity * $order->price, 2).')';
        $msg .= sprintf(' / %.2f <-> %.5f', $this->account->quoteAsset->totalAsset, $this->account->baseAsset->totalAsset);

        $this->log->info($msg);
    }

    public function __destruct()
    {
        ($this->log)->info('Shutting down...');
        if (isset($this->order) && $this->order->isNew()) {
            $this->api->cancel($this->order);
        }
        // TODO repay if no balance changes???? or keep for the next to avoid an hour interest fee
    }
}
