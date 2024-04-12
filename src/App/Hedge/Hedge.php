<?php
namespace App\Hedge;

use Binance\Account\Account;
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
     * @var array Prices to do hedging
     */
    protected array $range = [];
    /**
     * @var float difference between each step
     */
    protected float $step;

    protected Account $acc;

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
        protected readonly string            $keep,           // token to hold on
        protected readonly float             $min,
        protected readonly float             $max,
        protected readonly float             $amount
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

        $size = $this->findSize();
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
                $this->log->err("Unable to borrow $this->amount $this->keep. (Max: $max)");
                throw $e;
            }
        }

        // TODO use precision from exchangeInfo so that crypto-crypto pairs works
        $parts = count($this) - 1;
        $this->step = round(($this->max - $this->min) / $parts, 2);
        for ($i = 0; $i < $parts; $i++) {
            $this->range[] = $this->max - ($this->step * $i);
        }
        $this->range[] = $this->min;
        $this->log->info(sprintf(
            'Range: %.2f - %.2f (%.2f%%) / Step: %.2f (%.2f%%)',
            $this->min, $this->max, (($this->max - $this->min)/$this->max * 100),
            $this->step, ($this->step/$this->max * 100)
        ));

        for ($i = 0; $i < $size; $i++) {
            if ($this->new($i)) {
                $this->log($i);
            }
        }
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

    protected function log(int $index) : void
    {
        if (!$this[$index]) return;
        /** @var AbstractOrder $order */
        $order = $this[$index];

        if ($order->isFilled()) {
            $msg = '▷';
            $status = 'SELL' == $order->side ? 'SOLD' : 'BGHT';
        }
        else {
            $msg = '▶';
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

    /**
     * @throws BinanceException
     */
    private function account(): void
    {
        $this->acc = $this->api->getAccount($this->symbol);
        $msg = sprintf('%s: %.5f (%.5f). %s: %.2f (%.2f)',
            $this->acc->baseAsset->asset, $this->acc->baseAsset->free, $this->acc->baseAsset->borrowed,
            $this->acc->quoteAsset->asset, $this->acc->quoteAsset->free, $this->acc->quoteAsset->borrowed,
        );
        $this->log->info($msg);
    }

    private function findSize() : int
    {
        // Number of open orders is limited by remote side, so choose grid size
        $info = $this->api->exchangeInfo();
        return $info->getFilter($this->symbol, 'MAX_NUM_ALGO_ORDERS')['maxNumAlgoOrders'];
    }
}
