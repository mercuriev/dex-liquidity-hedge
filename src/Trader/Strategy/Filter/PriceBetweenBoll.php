<?php
namespace Trader\Strategy\Filter;

use Binance\Chart\AbstractChart;

readonly class PriceBetweenBoll
{
    public function __construct(
        private AbstractChart $chart, // minutes or seconds
        private int $period = 90,
        private float $minMp = 2.0,
        private float $maxMp = 2.0
    )
    {
    }

    public function __invoke($order)
    {
        $min = $this->chart->boll($this->period, $this->minMp)[0][2];
        $max = $this->chart->boll($this->period, $this->maxMp)[0][0];
        $now = $this->chart->now();
        return [($now >= $min && $now <= $max) ? $order : false];
    }
}
