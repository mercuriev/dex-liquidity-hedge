<?php
namespace Trader\Strategy\Filter;

use Binance\Chart\AbstractChart;

class PriceBelowEma
{
    public function __construct(
        private readonly AbstractChart $chart, // minutes or seconds
        private readonly int $emaPeriod,
        private readonly int $lookbackPeriod
    )
    {}

    public function __invoke($order)
    {
        if (!$order) return [null];

        $ema = $this->chart->ema($this->emaPeriod);
        return [($this->chart->isBelow($ema, $this->lookbackPeriod)) ? $order : null];
    }
}
