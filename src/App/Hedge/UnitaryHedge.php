<?php
namespace App\Hedge;

use App\Binance\LimitMakerOrder;
use Binance\Account\MarginIsolatedAccount;
use Binance\Chart\Minutes;
use Binance\Chart\Seconds;
use Binance\Event\Trade;
use Binance\Exception\BinanceException;
use Binance\Exception\ExceedBorrowable;
use Binance\MarginIsolatedApi;
use Binance\MarketDataApi;
use Binance\Order\AbstractOrder;
use Laminas\Log\Logger;

abstract class UnitaryHedge
{
    protected Seconds $sec;
    protected Minutes $min;
    protected MarginIsolatedAccount $account;
    protected LimitMakerOrder $order;

    protected float $median; // order entry / exit price
    protected float $fee = 0.0001; // median price offset
    protected int $precision; // base asset precision for rounding
    protected int $lastPost; // timestamp of last API request for order. Used for rate limit.

    /**
     * Base asset to sell or quote asset to buy.
     */
    abstract protected function getBorrowAsset() : string;

    /**
     * Quote value of all assets including borrowable.
     */
    abstract protected function getTotalQuoteValue() : float;


    /**
     * The constructor for the UnitaryHedge class.
     *
     * @param Logger $log Logs information about the execution of the code.
     * @param MarginIsolatedApi $api The Binance Margin API object.
     * @param float $low The lower price for the traded asset.
     * @param float $high The higher price for the traded asset.
     * @throws \InvalidArgumentException If the low price is higher or equal to the high price.
     * @throws \RuntimeException|BinanceException If failed to load klines.
     */
    public function __construct(
        protected Logger            $log,
        protected MarginIsolatedApi $api,
        protected float             $low,
        protected float             $high
    )
    {
        if ($this->low >= $this->high) throw new \InvalidArgumentException('Low price must be LOWER than high price');

        // the price assets are traded for on DEX. On CEX this places opposite order for the price.
        $this->median = round(($this->low + $this->high) / 2);
        $this->log->info(sprintf(
            '%s Range: %.2f - %.2f (%.2f%%) / Median: %.2f',
            $this->api->symbol,
            $this->low, $this->high, (($this->high - $this->low) / $this->high * 100),
            $this->median,
        ));

        // fetch account assets and display it
        $this->account = $this->api->getAccount($this->api->symbol);
        $this->log->info(sprintf('%s: %.5f (%.5f). %s: %.2f (%.2f)',
            $this->account->baseAsset->asset, $this->account->baseAsset->free, $this->account->baseAsset->borrowed,
            $this->account->quoteAsset->asset, $this->account->quoteAsset->free, $this->account->quoteAsset->borrowed,
        ));
        $this->log->info(sprintf('Total quote with borrowable: %.2f', $this->getTotalQuoteValue()));

        // precision of base asset's quantity
        $info = $this->api->exchangeInfo();
        $step = $info->getFilter($this->api->symbol, 'LOT_SIZE')['stepSize'];
        $this->precision = strlen($step) - strlen(ltrim($step, '0.')) - 1;

        // chart data to calculate technical analysis values
        $this->sec = new Seconds();
        $this->min = new Minutes();
        $klines = (new MarketDataApi([]))->getKlines([
            'symbol' => $this->api->symbol,
            'interval' => '1m',
            'startTime' => (time() - 660) * 1000, // last 10 minutes
            'endTime' => time() * 1000
        ]);
        if ($klines) $this->min->withKlines($klines);
        else throw new \RuntimeException('Failed to load minutes klines.');
    }

    /**
     * Feed Trade event from exchange so that this can match existing orders and post new.
     */
    public function __invoke(Trade $trade) : void
    {
        $this->sec->append($trade);
        $this->min->append($trade);

        // check if trade triggered our order
        if (isset($this->order) && !$this->order->isFilled()) {
            $this->order->match($trade);
            if ($this->order->isFilled()) {
                $this->account = $this->api->getAccount($this->api->symbol);
                $this->log($this->order);
            }
        }
    }

    /**
     * Posts orders to the Binance API endpoint.
     *
     * If LIMIT_MAKER order would match immediately do nothing, expected to be called again.
     * Saves result in $order property.
     *
     * The timestamp of the last API request is stored in $lastPost to rate limit calls.
     *
     * @param LimitMakerOrder $order Order to be posted.
     * @return bool
     * @throws BinanceException if any Binance API related exception occur.
     * @noinspection PhpFieldAssignmentTypeMismatchInspection api->post() always return the same type as argument
     */
    protected function post(LimitMakerOrder $order) : bool
    {
        try {
            $this->order = $this->api->post($order);
            return true;
        }
        catch (BinanceException $e) {
            if ($e->getCode() != -2010) { // Try again because: Order would immediately match and take.
                throw $e;
            }
        }
        finally {
            $this->lastPost = time();
        }
        return false;
    }

    /**
     * Creates a new LimitMakerOrder object by inverting the side of a given order.
     *
     * This function takes an existing LimitMakerOrder object and returns a new one,
     * with the trade side flipped. "BUY" becomes "SELL" and vice versa. The symbol,
     * price and quantity values are kept the same.
     *
     * @param LimitMakerOrder $order The original order for which a flip order will be created.
     * @return LimitMakerOrder A new LimitMakerOrder object with the trade side inverted but with the same symbol, price, and quantity.
     */
    protected function flip(LimitMakerOrder $order) : LimitMakerOrder
    {
        $flip = new LimitMakerOrder();
        $flip->symbol = $order->symbol;
        $flip->side = $order->side == 'BUY' ? 'SELL' : 'BUY';
        $flip->quantity = $order->quantity;
        $flip->price = round($this->median * ($order->side == 'BUY' ? 1 - $this->fee : 1 + $this->fee), 2);
        return $flip;
    }

    /**
     * Borrow asset to be traded up to maximum allowed amount. Asset name must be provided by child class.
     *
     * First, it fetches the maximum amount that can be borrowed for a given asset.
     * Then, it attempts to borrow this maximum amount.
     * If the operation succeeds, it updates the account information.
     * If it fails due to borrowing beyond the limit, it logs the error and rethrows the exception.
     *
     * @return float The maximum amount that was attempted to borrow.
     * @throws BinanceException if any other Binance operation-related error occurs.
     * @throws ExceedBorrowable if the borrowing operation exceeds the maximum allowable borrowable.
     */
    protected function borrow(): float
    {
        if ($this->account->marginLevel < 999) {
            if (!isset($this->lastPost)) { // log entry only for the first order to avoid flood
                $this->log->info('Skip borrow. Margin level: ' . $this->account->marginLevel);
            }
            return 0;
        }

        $asset = $this->getBorrowAsset();
        $max = $this->api->maxBorrowable($asset); // unlikely to fail but defines $max
        try {
            $this->api->borrow($asset, $max);
            $this->log->info(sprintf('Borrowed %.5f %s', $max, $this->getBorrowAsset()));
            // refresh account assets
            $this->account = $this->api->getAccount($this->api->symbol);
            return $max;
        }
        catch(ExceedBorrowable $e) {
            $this->log->err("Unable to borrow $max $asset. Exceed limit.");
            throw $e;
        }
        catch (BinanceException $e) {
            if (-3045 == $e->getCode()) { // "The system does not have enough asset now."
                $this->log->err("Binance pool of $asset is empty. Try later.");
            }
            throw $e;
        }
    }

    /**
     * Append account balance to order and log it.
     *
     * @param AbstractOrder $order
     * @return void
     */
    protected function log(AbstractOrder $order) : void
    {
        $msg = (string) $order;
        $msg .= sprintf(' / %.2f <-> %.5f', $this->account->quoteAsset->totalAsset, $this->account->baseAsset->totalAsset);
        $this->log->info($msg);
    }

    /**
     * The destructor for the UnitaryHedge class.
     *
     * Logs a message indicating that the system is shutting down.
     * Cancels order if any.
     */
    public function __destruct()
    {
        ($this->log)->info('Shutting down...');
        if (isset($this->order) && $this->order->isNew()) {
            $this->api->cancel($this->order);
            ($this->log)->info("Canceled $this->order");
        }
        // TODO repay if no balance changes???? or keep for the next to avoid an hour interest fee
    }
}
