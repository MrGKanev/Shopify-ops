<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';
$laravel = require dirname(__DIR__, 2).'/laravel/vendor/autoload.php';
$laravel->unregister();
$laravel->register(false);
