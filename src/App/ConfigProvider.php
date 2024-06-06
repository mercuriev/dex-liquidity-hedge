<?php
namespace App;

use Amqp\Channel;
use App\Command\BuyCommand;
use App\Command\CancelCommand;
use App\Command\DbCommand;
use App\Command\MonitorRangeCommand;
use App\Command\SellCommand;
use App\Command\StartCommand;
use App\Command\TradefeedCommand;
use App\Logger\AmqpWriter;
use App\Telegram\Action\BuyAction;
use App\Telegram\Action\CancelAction;
use App\Telegram\Action\SellAction;
use App\Telegram\Handler\MessageHandler;
use Interop\Container\Containerinterface;
use Laminas\Log\Filter\Priority;
use Laminas\Log\Logger;
use Laminas\Log\LoggerServiceFactory;
use Laminas\Log\Writer\AbstractWriter;
use Laminas\ServiceManager\Proxy\LazyServiceFactory;

class ConfigProvider
{
    public function __invoke() : array
    {
        ini_set('bcmath.scale', 8);


        // send important log entries to telegram
        return [
            'commands' => [
                'tradefeed'         => TradefeedCommand::class,
                'db'                => DbCommand::class,
                'start'             => StartCommand::class,
                'sell'              => SellCommand::class,
                'buy'               => BuyCommand::class,
                'cancel'            => CancelCommand::class,
                'monitor:range'     => MonitorRangeCommand::class,
                'telegram:start'    => Telegram\StartCommand::class
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
            'dependencies' => [
                'lazy_services' => [
                    'class_map' => [
                        // lazy service solves circular dep with Logger
                        Channel::class => Channel::class,
                    ],
                ],
                'delegators' => [
                    Channel::class => [
                        LazyServiceFactory::class,
                    ],
                ],
                'factories' => [
                    Logger::class => new class extends LoggerServiceFactory {
                        public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null) : Logger
                        {
                            $log = parent::__invoke($container, $requestedName, $options);
                            $writer = $container->get(AmqpWriter::class);
                            $writer->addFilter(Logger::NOTICE);
                            $log->addWriter($writer);
                            return $log;
                        }
                    }
                ]
            ],
        ];
    }
}
