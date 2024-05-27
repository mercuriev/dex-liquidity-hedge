<?php
namespace App;

use App\Command\BuyCommand;
use App\Command\DbCommand;
use App\Command\SellCommand;
use App\Command\StartCommand;
use App\Command\CancelCommand;
use App\Command\WatchCommand;
use App\Telegram\Action\PoolAction;
use App\Telegram\Handler\MessageHandler;

class ConfigProvider
{
    public function __invoke() : array
    {
        ini_set('bcmath.scale', 8);

        return [
            'commands' => [
                'watch'             => WatchCommand::class,
                'db'                => DbCommand::class,
                'start'             => StartCommand::class,
                'sell'              => SellCommand::class,
                'buy'               => BuyCommand::class,
                'cancel'            => CancelCommand::class,
                'telegram:start'    => \App\Telegram\StartCommand::class
            ],
            'rabbitmq' => [
                'host' => 'rabbitmq',
            ],
            'db' => [
                'driver' => 'Pdo_Mysql',
                'hostname' => 'mysql',
                'database' => 'hedgehog',
                'username' => 'root',
                'password' => '',
                'driver_options' => array(
                    \PDO::MYSQL_ATTR_INIT_COMMAND => 'SET time_zone = "+0:0"',
                    \PDO::ATTR_PERSISTENT => true
                )
            ],
            'telegram' => [
                'db' => ['database' => 'telegram'],
                'handlers'  => [
                    MessageHandler::class,
                    PoolAction::class
                ]
            ]
        ];
    }
}
