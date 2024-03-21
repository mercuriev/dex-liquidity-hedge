<?php
use Laminas\Log\Logger;
use Laminas\ServiceManager\ServiceManager;

// Load configuration
$config = require __DIR__ . '/config.php';

$dependencies = $config['dependencies'];
$dependencies['services']['config'] = $config;

// Build container
$GLOBALS['container'] = $container = new ServiceManager($dependencies);
$container->setAllowOverride(true);

// Init logging early (catch errors)
$container->get(Logger::class);

return $container;
