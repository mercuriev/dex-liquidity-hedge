<?php
namespace App;

use App\Command\BuyCommand;
use App\Command\SellCommand;
use App\Command\StartCommand;

class ConfigProvider
{
    public function __invoke() : array
    {
        ini_set('bcmath.scale', 8);

        return [
            'commands' => [
                'start'     => StartCommand::class,
                'sell'      => SellCommand::class,
                'buy'       => BuyCommand::class
            ]
        ];
    }
}
