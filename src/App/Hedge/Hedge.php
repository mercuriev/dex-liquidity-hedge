<?php
namespace App\Hedge;

use Binance\Account\Account;
use Binance\Event\Trade;
use Binance\Exception\BinanceException;
use Binance\Exception\ExceedBorrowable;
use Binance\Exception\InsuficcientBalance;
use Binance\Exception\StopPriceTrigger;
use Binance\MarginIsolatedApi;
use Binance\Order\AbstractOrder;
use Binance\Order\LimitOrder;
use Binance\Order\StopOrder;
use Laminas\Log\Logger;
use Laminas\Log\Processor\ProcessorInterface;


abstract class Hedge extends \SplFixedArray
{
    /**
     * @var array Prices to do hedging
     */
    protected array $range = [];
    /**
     * @var float difference between each step
     */
    protected float $step;

    protected Account $acc;

    /**
     * Build order to enter hedging position.
     *
     * @param int $index
     * @return AbstractOrder
     */
    abstract protected function do(int $index) : AbstractOrder;

    /**
     * Build order to exit hedging position in case of price reverse.
     *
     * @param int $index
     * @return AbstractOrder
     */
    abstract protected function undo(int $index) : AbstractOrder;

    public function __construct(
        protected Logger                     $log,
        protected readonly MarginIsolatedApi $api,
        protected readonly string            $symbol,
        protected readonly string            $keep,           // token to hold on
        protected readonly float             $min,
        protected readonly float             $max,
        protected readonly float             $amount
    )
    {
        // Prepend log entries with "HEDGE symbol:"
        $this->log = clone $log;
        $this->log->addProcessor(new class($this->symbol) implements ProcessorInterface {
            public function __construct(private string $symbol) {}
            public function process(array $event)
            {
                $event['message'] = implode(' ', [
                    'Hedge', $this->symbol, ':', $event['message']
                ]);
                return $event;
            }
        });

        //
        $api->symbol = $this->symbol;

        // Number of open orders is limited by remote side, so choose grid size
        $info = $this->api->exchangeInfo();
        $size = $info->getFilter($this->symbol, 'MAX_NUM_ALGO_ORDERS')['maxNumAlgoOrders'];
        parent::__construct($size);

        // TODO borrow exactly as much is not enough to have $amount
        $this->account();
        $max = $this->api->maxBorrowable($this->keep);
        $this->log->info(sprintf('Max borrowable %s: %.5f', $this->keep, $max));
        if ($this->amount > $this->acc->baseAsset->free) {
            try {
                $this->api->borrow($this->keep, $this->amount);
                $this->account();
            }
            catch(ExceedBorrowable $e) {
                $max = $this->api->maxBorrowable($this->keep);
                $this->log->err("Unable to borrow {$this->amount} {$this->keep}. (Max: $max)");
                throw $e;
            }
        }

        // TODO use precision from exchangeInfo
        // TODO stop limit prices to make it behave like market order (avoid slippery)
        $parts = count($this) - 1;
        $this->step = round(($this->max - $this->min) / $parts, 2);
        for ($i = 0; $i < $parts; $i++) {
            $this->range[] = $this->max - ($this->step * $i);
        }
        $this->range[] = $this->min;
        $this->log->info(sprintf('Range: %.2f - %.2f (Step: %.2f)', min($this->range), max($this->range), $this->step));

        for ($i = 0; $i < $size; $i++) {
            $order = $this->do($i);
            $this[$i] = $this->post($order);
            $this->log->info(sprintf('▷%u: %-4s %.2f', $i, $order->side, $order->price));
        }
    }

    public function __destruct()
    {
        foreach ($this as $o) {
            $this->api->cancel($o);
        }
        // TODO repay if no balance changes???? or keep for the next to avoid an hour interest fee
    }

    /**
     * Update the current market price and take action.
     *
     * @param float $price
     * @return void
     * @throws ExceedBorrowable
     */
    public function __invoke(Trade $trade)
    {
        /**
         * @var StopOrder|LimitOrder $order
         */
        foreach ($this as $i => $order)
        {
            if ($order->match($trade)) {
                if ($order->isFilled()) {
                    $this->log->info(sprintf('▶%u: %-4s %.2f', $i,
                        'SELL' == $order->side ? 'SOLD' : 'BGHT',
                    $order->price));

                    $mirror = $this->undo($i);
                    $this[$i] = $this->post($mirror);

                    $this->log->info(sprintf('▷%u: %-4s %.2f', $i, $mirror->side, $mirror->price));

                    // replace all other filled with opposite order
                    /*foreach ($this as $j => $other) {
                        if ($i == $j) continue; // do not replace current
                        if ($other->isFilled()) {
                            $mirror = $this->mirror($other);
                            $this[$j] = $this->post($mirror);
                        }
                    }*/
                }
            }
        }
    }

    /**
     * If stop price is rejected post LIMIT order instead.
     *
     * @param StopOrder $order
     * @return StopOrder|LimitOrder
     * @throws BinanceException
     * @throws StopPriceTrigger
     * @throws \Binance\Exception\InsuficcientBalance
     * @throws \Binance\Exception\InvalidPrices
     */
    private function post(StopOrder|LimitOrder $order) : StopOrder|LimitOrder
    {
        // TODO autoRepayAtCancel = FALSE (so that we not pay 1hr interest each order)
        try {
            $order = $this->api->post($order);
        }
        catch (StopPriceTrigger) { // current price is lower
            $limit = new LimitOrder();
            $limit->symbol = $this->symbol;
            $limit->side = 'SELL';
            $limit->price = $order->price;
            $limit->quantity = $order->quantity;
            $order = $this->api->post($limit);
        }
        catch (InsuficcientBalance $e) {
            xdebug_break();
        }
        return $order;
    }

    private function account(bool $log = true)
    {
        $this->acc = $this->api->getAccount($this->symbol);
        if ($log) {
            $msg = sprintf('%s: %.5f (%.5f). %s: %.2f (%.2f)',
                $this->acc->baseAsset->asset, $this->acc->baseAsset->free, $this->acc->baseAsset->borrowed,
                $this->acc->quoteAsset->asset, $this->acc->quoteAsset->free, $this->acc->quoteAsset->borrowed,
            );
            $this->log->info($msg);
        }
        return $this->acc;
    }
}
