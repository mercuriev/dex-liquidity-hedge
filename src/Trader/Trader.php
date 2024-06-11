<?php
namespace Trader;

use Binance\Account\Account;
use Binance\Chart\Chart;
use Binance\Event\Trade;
use Binance\Exception\BinanceException;
use Binance\Order\AbstractOrder;
use Binance\Order\LimitMakerOrder;
use Binance\SpotApi;
use Laminas\Db\Adapter\Adapter;
use Laminas\Log\Logger;
use Trader\Model\Deal;
use Trader\Strategy\Filter\PriceBetweenBoll;
use Trader\Strategy\Filter\RsiBetween;
use Trader\Strategy\Pipeline;
use function Binance\truncate;

/**
 * Tracks account balances and current orders/deals.
 * Build strategies.
 * Execute strategies to find enter/exit points.
 * Execute orders.
 * Store deals in DB.
 *
 * Trader is orchestrating API calls and data persistence.
 * It does not make entry/exit decision on its own but delegate it to Strategy.
 */
class Trader
{
    public const string ORDER_PREFIX = 'bot';

    protected Account $account;
    protected ?Deal $deal;

    protected Pipeline $enterStrategy;
    protected Pipeline $exitStrategy;

    /**
     *  2. Fetch required data for technical analysis.
     *  3. Load saved state from DB.
     */
    public function __construct(
        protected Logger $log,
        protected Adapter $db,
        protected SpotApi $api,
        protected Chart $chart,
        array $options
    )
    {
        $this->api->symbol = $symbol = $options['symbol'];

        $this->account  = $this->api->getAccount();
        $this->account->selectAssetsFor($symbol);
        $this->log->info(sprintf(
            '%s balance: %.8f / %.8f',
            $symbol,
            $this->account->quoteAsset->free,
            $this->account->baseAsset->free
        ));

        // recover state
        $this->deal = $this->loadActiveDeal() ?? new Deal($this->db);
        $this->deal->symbol ??= $symbol;
        $this->api->cancelAll(static::ORDER_PREFIX); // TODO can we continue without cancel?

        $this->buildStrategies();
    }

    public function __invoke(Trade $trade): bool
    {
        $this->chart->append($trade);
        if (isset($this->deal)) {
            $this->deal->entry?->match($trade);
            $this->deal->exit?->match($trade);
        }

        // take action every second and minute
        if (1 == count($this->chart->s[0])) {
            $this->second($trade);
            if (1 == count($this->chart->m[0])) {
                $this->minute($trade);
            }
        }

        return true;
    }

    protected function second(Trade $trade): void
    {
        // entry orders
        if (!$this->deal->entry) {
            $deal = ($this->enterStrategy)($this->deal);
            if ($deal && $deal->entry instanceof AbstractOrder) {
                try {
                    $deal->entry = $this->api->post($deal->entry);
                    $deal->save();
                    return; // one request per second
                }
                catch (BinanceException $e) {}
            }
            else return; // no entry at this time
        }

        // exit orders
        elseif ($this->deal->entry->isFilled() && !$this->deal?->exit?->isFilled()) {
            $deal = ($this->exitStrategy)($this->deal);
            if ($deal && $deal->exit instanceof AbstractOrder) {
                try {
                    // TODO replace
                    $deal->exit = $this->api->post($deal->exit);
                    $deal->save();
                    return; // one request per second
                }
                catch (BinanceException $e) {}
            }
            else return; // no exit at this time
        }

        // complete deal
        elseif ($this->deal->exit->isFilled()) {
            $this->deal->save();
            $this->deal = null;
        }

        else throw new \RuntimeException('unreachable');
    }

    protected function minute(Trade $trade): void
    {
    }

    private function buildStrategies(): void
    {
        $minutes = (new Pipeline())
            ->pipe(new PriceBetweenBoll($this->chart->m, 30))
            ->pipe(new RsiBetween($this->chart->m, 0, 30))
        ;

        $this->enterStrategy = (new Pipeline())
            ->pipe($minutes)
            ->pipe(new PriceBelowEma($this->chart->s, 30, 1))
            ->pipe(new RsiBetween($this->chart->s, 6, 0, 50))
            ->pipe(function () {
                return new LimitMakerOrder();
            })
        ;

        $this->exitStrategy = new Pipeline();
    }

    private function loadActiveDeal(): ?Deal
    {
        $sql = 'SELECT * FROM deal 
                WHERE `status` != "DONE"
                AND `timestamp` > NOW() - INTERVAL 30 SECOND -- deal memory time
                ORDER BY `timestamp` DESC';
        $deal = $this->db->query($sql)->execute()->current();
        // FIXME unserialize stored data
        if ($deal) {
            // TODO save and recover strategy object
            return (new Deal($this->db))->populate($deal);
        }
        return null;
    }
}
