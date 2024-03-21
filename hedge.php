<?php
require 'vendor/autoload.php';

use Symfony\Component\Console\Application;

$app = new Application();
$app->setCatchExceptions(false);
$container = require 'config/container.php';
$config = $container->get('config');
foreach ($config['commands'] ?? [] as $serviceName) {
    $cmd = $container->get($serviceName);
    $app->add($cmd);
}

$app->run();
