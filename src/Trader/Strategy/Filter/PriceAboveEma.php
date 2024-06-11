<?php
namespace Trader\Strategy\Filter;

use Binance\Chart\AbstractChart;
use Trader\Model\Deal;

readonly class PriceAboveEma
{
    public function __construct(
        private AbstractChart $chart, // minutes or seconds
        private int $emaPeriod,
        private int $lookbackPeriod
    ) {}

    public function __invoke(Deal $deal)
    {
        $ema = $this->chart->ema($this->emaPeriod);
        if ($this->chart->isAbove($ema, $this->lookbackPeriod))
            return $deal;
        else
            return false;
    }
}
