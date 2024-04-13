<?php
namespace App\Hedge;

use Binance\Account\Account;
use Binance\Entity\ExchangeInfo;
use Binance\Event\Trade;
use Binance\Exception\BinanceException;
use Binance\Exception\ExceedBorrowable;
use Binance\MarginIsolatedApi;
use Binance\Order\AbstractOrder;
use Binance\Order\LimitOrder;
use Binance\Order\StopOrder;
use Laminas\Log\Logger;
use Laminas\Log\Processor\ProcessorInterface;


abstract class Hedge extends \SplFixedArray
{
    /**
     * @var array Price levels to put orders.
     */
    protected array $prices = [];

    /**
     * @var float Price difference between each level.
     */
    protected float $step;

    protected Account $account;
    protected ExchangeInfo $info;
    protected int $precision;

    abstract protected function getBorrowAsset() : string;
    abstract protected function new(int $index) : ?AbstractOrder;
    abstract protected function filled(int $index) : ?AbstractOrder;

    /**
     * @throws ExceedBorrowable
     * @throws BinanceException
     */
    public function __construct(
        protected Logger                     $log,
        protected readonly MarginIsolatedApi $api,
        protected readonly string            $symbol,
        protected readonly float             $min,
        protected readonly float             $max
    )
    {
        // Prepend log entries with "HEDGE symbol:"
        $this->log = clone $log;
        $this->log->addProcessor(new class($this->symbol) implements ProcessorInterface {
            public function __construct(private readonly string $symbol) {}
            public function process(array $event): array
            {
                $event['message'] = implode(' ', [
                    'Hedge', $this->symbol, ':', $event['message']
                ]);
                return $event;
            }
        });

        //
        $api->symbol = $this->symbol;

        // TODO borrow exactly as much is not enough to have $amount
        $this->fetchAccount();
        $this->borrow();

        $size = $this->callApiForMaxOrders();
        parent::__construct($size);

        //
        $step = $this->info->getFilter($this->symbol, 'LOT_SIZE')['stepSize'];
        $this->precision = strlen($step) - strlen(ltrim($step, '0.')) - 1;

        // first call fills the prices array and calc step
        $this->prices = $this->getPrices();
        $this->log->info(sprintf(
            'Range: %.2f - %.2f (%.2f%%) / Step: %.2f (%.2f%%)',
            $this->min, $this->max, (($this->max - $this->min)/$this->max * 100),
            $this->step, ($this->step/$this->max * 100)
        ));

        // populate full of orders online
        for ($i = 0; $i < $size; $i++) {
            if ($this->new($i)) {
                $this->log($i);
            }
        }
    }

    /**
     * Feed Trade event from exchange so that this can match existing orders and post new.
     */
    public function __invoke(Trade $trade) : void
    {
        /** @var StopOrder|LimitOrder $order */
        foreach ($this as $i => $order)
        {
            if ($order->match($trade)) {
                if ($order->isFilled()) {
                    $this->log($i);

                    $mirror = $this->filled($i);

                    // log again that new order is POST'ed
                    if ($mirror) $this->log($i);
                }
            }
        }
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
            if (0 == $max) { // FIXME here is assumed that already borrowed the same asset. Work out cases when opposite asset loan limits total borrowable.
                return;
            }
        }
        catch (BinanceException $e) {
            if (-3045 == $e->getCode()) { // "The system does not have enough asset now."
                $this->log->err("Binance pool of $asset is empty. Try later.");
            }
            throw $e;
        }

        try {
            $this->api->borrow($asset, $max);
            $this->fetchAccount();
        }
        catch(ExceedBorrowable $e) {
            $max = $this->api->maxBorrowable($asset);
            $this->log->err("Unable to borrow $max $asset. Exceed limit.");
            throw $e;
        }
    }

    /**
     * @throws BinanceException
     */
    private function repayAll() : void
    {
        if ($this->account->baseAsset->borrowed > 0) {
            $this->api->repay($this->account->baseAsset->asset, $this->account->baseAsset->borrowed);
        }

        if ($this->account->quoteAsset->borrowed > 0) {
            $this->api->repay($this->account->quoteAsset->asset, $this->account->quoteAsset->borrowed);
        }
    }

    protected function log(int $index) : void
    {
        if (!$this[$index]) return;
        /** @var AbstractOrder $order */
        $order = $this[$index];

        if ($order->isFilled()) {
            $msg = '▶';
            $status = 'SELL' == $order->side ? 'SOLD' : 'BGHT';
        }
        else {
            $msg = '▷';
            $status = $order->side;
        }
        $msg .= sprintf(
            '%u: %-4s %.2f',
            $index,
            $status,
            $order->price
        );
        $msg .= (isset($order->stopPrice) ? sprintf(' @ %.2f', $order->stopPrice) : '');

        $this->log->info($msg);
    }

    protected function getPrices() : array
    {
        if (!$this->prices) {

            // TODO use precision from exchangeInfo so that crypto-crypto pairs works
            $parts = count($this) - 1;
            $this->step = round(($this->max - $this->min) / $parts, 2);
            for ($i = 0; $i < $parts; $i++) {
                $this->prices[] = $this->max - ($this->step * $i);
            }
            $this->prices[] = $this->min;
        }
        return $this->prices;
    }

    /**
     * @throws BinanceException
     */
    protected function fetchAccount(): void
    {
        $this->account = $this->api->getAccount($this->symbol);
        $msg = sprintf('%s: %.5f (%.5f). %s: %.2f (%.2f)',
            $this->account->baseAsset->asset, $this->account->baseAsset->free, $this->account->baseAsset->borrowed,
            $this->account->quoteAsset->asset, $this->account->quoteAsset->free, $this->account->quoteAsset->borrowed,
        );
        $this->log->info($msg);
    }

    private function callApiForMaxOrders() : int
    {
        // Number of open orders is limited by remote side, so choose grid size
        $this->info = $this->api->exchangeInfo();
        return $this->info->getFilter($this->symbol, 'MAX_NUM_ALGO_ORDERS')['maxNumAlgoOrders'];
    }

    /**
     * Cancel all ongoing trades.
     *
     */
    public function __destruct()
    {
        foreach ($this as $o) {
            if ($o) {
                $this->api->cancel($o);
            }
        }
        // TODO repay if no balance changes???? or keep for the next to avoid an hour interest fee
    }
}
