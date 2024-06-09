<?php
namespace Trader\Strategy;

use Trader\Model\Deal;

class InterruptibleProcessor
{
    public function __construct(private $checkFn) {}

    public function process(Deal $deal, callable ...$stages)
    {
        foreach ($stages as $stage) {
            $deal = $stage($deal);

            if (true !== ($this->checkFn)($deal)) {
                return $deal;
            }
        }

        return $deal;
    }
}
