<?php

use Interop\Container\Containerinterface;
use Laminas\ConfigAggregator\ArrayProvider;
use Laminas\ConfigAggregator\ConfigAggregator;
use Laminas\ConfigAggregator\PhpFileProvider;
use Laminas\ServiceManager\Factory\AbstractFactoryInterface;

$aggregator = new ConfigAggregator([
    \Laminas\Log\ConfigProvider::class,
    \App\ConfigProvider::class,

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
                \Laminas\ServiceManager\AbstractFactory\ReflectionBasedAbstractFactory::class
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
