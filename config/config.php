<?php

use Amqp\Channel;
use App\Logger\AmqpWriter;
use Interop\Container\Containerinterface;
use Laminas\ConfigAggregator\ArrayProvider;
use Laminas\ConfigAggregator\ConfigAggregator;
use Laminas\ConfigAggregator\PhpFileProvider;
use Laminas\Log\Logger;
use Laminas\Log\LoggerServiceFactory;
use Laminas\ServiceManager\Factory\AbstractFactoryInterface;
use Laminas\ServiceManager\Proxy\LazyServiceFactory;

$aggregator = new ConfigAggregator([
    \Laminas\Log\ConfigProvider::class,
    \App\ConfigProvider::class,
    \Laminas\Db\ConfigProvider::class,

    new ArrayProvider([
        'dependencies' => [
            'abstract_factories' => [
                // call ::factory($sm) if defined in target service
                new class() implements AbstractFactoryInterface
                {
                    public function canCreate(ContainerInterface $container, $requestedName): bool
                    {
                        return method_exists($requestedName, 'factory');
                    }
                    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
                    {
                        return $requestedName::factory($container, $requestedName, $options);
                    }
                },

                // Enable container objects to be created by looking at construct parameters
                \Laminas\ServiceManager\AbstractFactory\ReflectionBasedAbstractFactory::class,
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
            ],
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
        ]
    ]),

    new PhpFileProvider(realpath(__DIR__) . '/local/*.php'),
    // must be last
    function() {
        return @$_ENV['PHPUNIT'] ? include 'test/config.php' : [];
    }
]);

return $aggregator->getMergedConfig();
