<?php
namespace Trader;

use Binance\Account\Account;
use Binance\Chart\Chart;
use Binance\Event\Trade;
use Binance\SpotApi;
use Laminas\Db\Adapter\Adapter;
use Laminas\Log\Logger;
use Trader\Model\Deal;

class Trader
{
    public const string ORDER_PREFIX = 'bot';

    protected readonly Chart $chart;
    protected Account $account;
    protected ?Deal $deal;

    /**
     *  2. Fetch required data for technical analysis.
     *  3. Load saved state from DB.
     */
    public function __construct(
        protected Logger $log,
        protected Adapter $db,
        protected SpotApi $api,
        array $options
    )
    {
        $this->api->symbol = $symbol = $options['symbol'];

        $this->account  = $this->api->getAccount();
        foreach ($this->account->balances as $balance) {
            if (str_contains($symbol, $balance['asset'])) {
                $this->log->info(sprintf('%s balance: %.8f / %.8f', $balance['asset'], $balance['free'], $balance['locked']));
            }
        }

        // recover state
        $this->deal = $this->loadActiveDeal();
        $this->api->cancelAll(static::ORDER_PREFIX); // TODO can we continue without cancel?

        // Fetch chart from API/DB (must be after queue consume so that Trades are not lost while AMQP is starting)
        $this->chart = Chart::buildWithHistory($symbol);
    }

    public function __invoke(Trade $trade): bool
    {
        $this->chart->append($trade);
        if (isset($this->deal)) {
            $this->deal->orderIn?->match($trade);
            $this->deal->orderOut?->match($trade);
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
    }

    protected function minute(Trade $trade): void
    {
    }

    private function loadActiveDeal(): ?Deal
    {
        $sql = 'SELECT * FROM deal 
                WHERE `status` != "DONE"
                AND `timestamp` > NOW() - INTERVAL 30 SECOND -- deal memory time
                ORDER BY `timestamp` DESC';
        $deal = $this->db->query($sql)->execute()->current();

        if ($deal) {
            // TODO save and recover strategy object
            return (new Deal($this->db))->populate($deal);
        }
        return null;
    }
}
