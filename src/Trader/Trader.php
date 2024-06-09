<?php
namespace Trader;

use Binance\Chart\Chart;
use Binance\Event\Trade;
use Binance\SpotApi;
use Laminas\Db\Adapter\Adapter;
use Laminas\Log\Logger;

class Trader
{
    protected Chart $chart;
    protected readonly Chart $chart;

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

        // Fetch chart from API/DB (must be after queue consume so that Trades are not lost while AMQP is starting)
        $this->chart = Chart::buildWithHistory($symbol);
    }

    public function __invoke(Trade $trade): bool
    {
        $this->chart->append($trade);

        return true;
    }
}
