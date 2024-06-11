<?php
namespace Trader\Strategy\Filter;

use Binance\Chart\AbstractChart;
use Trader\Model\Deal;

readonly class PriceBelowEma
{
    public function __construct(
        private AbstractChart $chart, // minutes or seconds
        private int           $emaPeriod,
        private int           $lookbackPeriod
    )
    {}

    public function __invoke(Deal $deal)
    {
        $ema = $this->chart->ema($this->emaPeriod);
        return ($this->chart->isBelow($ema, $this->lookbackPeriod)) ? $deal : false;
    }
}
