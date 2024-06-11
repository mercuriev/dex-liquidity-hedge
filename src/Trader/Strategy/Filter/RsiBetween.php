<?php
namespace Trader\Strategy\Filter;

use Binance\Chart\AbstractChart;
use Trader\Model\Deal;

readonly class RsiBetween
{
    public function __construct(
        private AbstractChart $chart, // minutes or seconds
        private int $min,
        private int $max,
        private int $period = 3
    ) {}

    public function __invoke(Deal $deal)
    {
        $rsi = $this->chart->rsi($this->period)[0];
        return ($rsi >= $this->min && $rsi <= $this->max) ? $deal : false;
    }
}
