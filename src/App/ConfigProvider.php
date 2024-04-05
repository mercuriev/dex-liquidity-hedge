<?php
namespace App;

use App\Command\DownCommand;
use App\Command\KeepCommand;
use App\Command\StartCommand;

class ConfigProvider
{
    public function __invoke() : array
    {
        ini_set('bcmath.scale', 8);

        return [
            'commands' => [
                'start'     => StartCommand::class,
                'keep'      => KeepCommand::class,
                'down'      => DownCommand::class
            ]
        ];
    }
}
