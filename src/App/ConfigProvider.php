<?php
namespace App;

use App\Command\BuyCommand;
use App\Command\DbCommand;
use App\Command\SellCommand;
use App\Command\StartCommand;
use App\Command\CancelCommand;
use App\Command\WatchCommand;
use App\Telegram\Action\BuyAction;
use App\Telegram\Action\CancelAction;
use App\Telegram\Action\PoolAction;
use App\Telegram\Action\SellAction;
use App\Telegram\Handler\MessageHandler;
use Laminas\Log\Logger;
use Laminas\Log\LoggerServiceFactory;
use Psr\Container\ContainerInterface;

class ConfigProvider
{
    public function __invoke() : array
    {
        ini_set('bcmath.scale', 8);

        return [
            'dependencies' => [
                'factories' => [
                    // overwrite solely to disable Logger destructor that is called before Hedge destructor
                    // original Logger removes writers hence Hedge desctructor can't write to log anymore
                    // and the Logger destructor is called first because it is instantiated first
                    Logger::class => new class extends LoggerServiceFactory {
                        public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null) : Logger
                        {
                            // Configure the logger
                            $config    = $container->get('config');
                            $logConfig = $config['log'] ?? [];
                            $this->processConfig($logConfig, $container);
                            return new class($logConfig) extends Logger {
                                public function __destruct() {}
                            };
                        }
                    }
                ]
            ],
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
                    SellAction::class,
                    BuyAction::class,
                    CancelAction::class
                ]
            ]
        ];
    }
}
