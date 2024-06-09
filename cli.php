<?php
require 'vendor/autoload.php';

use Symfony\Component\Console\Application;
use Symfony\Component\Console\CommandLoader\ContainerCommandLoader;

// call destructors on Ctrl+C
pcntl_async_signals(true);
$callShutdown = function () { exit(0); };
pcntl_signal(SIGINT, $callShutdown);
pcntl_signal(SIGTERM, $callShutdown);

global $container;
$container = require 'config/container.php';

$app = new Application();
$app->setCatchExceptions(false);

$config = $container->get('config');
$app->setCommandLoader(new ContainerCommandLoader($container, $config['commands']));
$app->run();
