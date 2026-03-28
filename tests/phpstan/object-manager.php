<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__, 2) . '/.env');
}

$_SERVER['APP_ENV'] ??= 'test';
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'];

$kernel = new App\Kernel($_SERVER['APP_ENV'], false);
$kernel->boot();

return $kernel->getContainer()->get('doctrine')->getManager();
