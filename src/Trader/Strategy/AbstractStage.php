<?php
namespace Trader\Strategy;
use Binance\Account\Account;
use Binance\Order\AbstractOrder;
use Trader\Model\Deal;

abstract class AbstractStage
{
    public ?Account $account;
    public ?Deal $deal;

    /**
     * @param $payload
     * @return AbstractOrder|bool false to interrupt the pipeline
     */
    abstract public function __invoke($payload): AbstractOrder|bool;
}
