<?php
require 'vendor/autoload.php';

use Symfony\Component\Console\Application;
use Symfony\Component\Console\CommandLoader\ContainerCommandLoader;

$container = require 'config/container.php';

$app = new Application();
$app->setCatchExceptions(false);

$config = $container->get('config');
$app->setCommandLoader(new ContainerCommandLoader($container, $config['commands']));
$app->run();
