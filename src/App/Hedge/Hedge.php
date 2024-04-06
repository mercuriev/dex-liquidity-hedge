<?php
namespace App\Hedge;

use Binance\Account\Account;
use Binance\Event\Trade;
use Binance\Exception\BinanceException;
use Binance\Exception\ExceedBorrowable;
use Binance\Exception\StopPriceTrigger;
use Binance\MarginIsolatedApi;
use Binance\Order\LimitOrder;
use Binance\Order\StopOrder;
use Laminas\Log\Logger;
use Laminas\Log\Processor\ProcessorInterface;


abstract class Hedge extends \SplFixedArray
{
    private Account $acc;

    public function __construct(
        private Logger                     $log,
        private readonly MarginIsolatedApi $api,
        private readonly string            $symbol,
        private readonly string            $keep,           // token to hold on
        private readonly float             $min,
        private readonly float             $max,
        private readonly float             $amount
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
        // +1 because lowest step doesn't have an open order
        $amount = round($this->amount / (count($this) + 1), 5, PHP_ROUND_HALF_DOWN);
        $prices = $this->calcPrices();
        for ($i = 0; $i < $size; $i++) {
            $order = new StopOrder();
            $order->symbol = $this->symbol;
            $order->side = 'SELL';
            $order->quantity = $amount;
            $order->setPrice($prices[$i]);
            $this[$i] = $this->post($order);
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
                $this->log->info($order->oneline());
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
        #$this->log->info($order->oneline());
        $this->log->info(sprintf('%s %s %.2f %.5f', $order->orderId, $order->side, $order->price, $order->quantity));
        return $order;
    }

    private function calcPrices() : array
    {
        $interval = ($this->max - $this->min);
        $parts = count($this) - 1;
        // TODO use precision from exchangeinfo
        $step = round($interval / $parts, 2);

        $milestones = [];
        for ($i = 0; $i < $parts; $i++) {
            $milestones[] = $this->max - ($step * $i);
        }
        $milestones[] = $this->min;
        return $milestones;
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