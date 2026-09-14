<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';
$laravel = require dirname(__DIR__, 2).'/laravel/vendor/autoload.php';
$laravel->unregister();
$laravel->register(false);

// Minimal `config()` support for plain domain classes (e.g. OrderTypeClassifier)
// that read settings via the config() helper instead of a constructor
// parameter. This binds a bare container + config repository only — no
// Application, no HTTP kernel, no DB, no service providers. Anything needing
// more than config() lookups is out of scope for this harness by design; see
// docs/superpowers/specs/2026-09-13-parity-verification-design.md.
$container = new Illuminate\Container\Container();
Illuminate\Container\Container::setInstance($container);
$container->singleton('config', fn () => new Illuminate\Config\Repository([
    'order-types' => require dirname(__DIR__, 2).'/laravel/config/order-types.php',
]));
