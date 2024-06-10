<?php

namespace Trader;

use Trader\Command\BacktestCommand;
use Trader\Command\LoadTradesCommand;
use Trader\Command\TraderCommand;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'commands' => [
                'trader'            => TraderCommand::class,
                'trader:backtest'   => BacktestCommand::class,
                'trader:load-trades'=> LoadTradesCommand::class
            ],
        ];
    }
}
