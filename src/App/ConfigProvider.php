<?php
namespace App;

use App\Command\Cli\BuyCommand;
use App\Command\Cli\CancelCommand;
use App\Command\Cli\SellCommand;
use App\Command\DbCommand;
use App\Command\FeedCommand;
use App\Command\HedgeCommand;
use App\Command\Monitor\MonitorInventoryCommand;
use App\Command\Monitor\MonitorRangeCommand;
use App\Telegram\Action\BuyAction;
use App\Telegram\Action\CancelAction;
use App\Telegram\Action\SellAction;
use App\Telegram\Handler\MessageHandler;

class ConfigProvider
{
    public function __invoke() : array
    {
        ini_set('bcmath.scale', 8);


        // send important log entries to telegram
        return [
            'commands' => [
                'feed'              => FeedCommand::class,
                'db'                => DbCommand::class,
                'hedge'             => HedgeCommand::class,
                'sell'              => SellCommand::class,
                'buy'               => BuyCommand::class,
                'cancel'            => CancelCommand::class,
                'monitor:range'     => MonitorRangeCommand::class,
                'monitor:inventory' => MonitorInventoryCommand::class,
                'telegram:start'    => Telegram\StartCommand::class
            ],
            'feed' => [
                'symbols' => ['bnbfdusd']
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
                    SellAction::class,
                    BuyAction::class,
                    CancelAction::class
                ]
            ],
        ];
    }
}
