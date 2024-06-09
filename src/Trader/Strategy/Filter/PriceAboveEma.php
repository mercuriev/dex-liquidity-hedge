<?php
namespace Trader\Strategy\Filter;

class PriceAboveEma
{
    public function __construct(private int $emaPeriod, private int $lookbackPeriod) {}

    public function __invoke($chart)
    {
        $ema = $chart->s->ema($this->emaPeriod);
        if ($chart->s->isAbove($ema, $this->lookbackPeriod))
            return $chart;
        else return null;
    }
}
