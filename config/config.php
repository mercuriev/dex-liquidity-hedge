<?php
use Laminas\ConfigAggregator\ConfigAggregator;
use Laminas\ConfigAggregator\PhpFileProvider;

$aggregator = new ConfigAggregator([
    \Laminas\Log\ConfigProvider::class,
    \App\ConfigProvider::class,

    new PhpFileProvider(realpath(__DIR__) . '/local/*.php'),

    // must be last
    function() {
        return @$_ENV['PHPUNIT'] ? include 'test/config.php' : [];
    }
]);

return $aggregator->getMergedConfig();
