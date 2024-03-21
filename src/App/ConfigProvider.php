<?php
namespace App;

use App\Command\DownCommand;

class ConfigProvider
{
    public function __invoke() : array
    {
        ini_set('bcmath.scale', 8);

        return [
            'commands' => [
                DownCommand::class
            ]
        ];
    }
}
