<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

/*
 * ARCH-07: the harness preset (WordPress classes and functions, the tests), plus what is specific to this package.
 */
$configuration = require __DIR__.'/vendor/pollora/harness/presets/composer-dependency-analyser.php';

return $configuration
    // Action Scheduler is a plugin and Cron_Event_Command belongs to WP-CLI: neither is a Composer dependency.
    ->ignoreUnknownClassesRegex('~^(ActionScheduler|Cron_Event_Command$)~')
    // illuminate/collections and the discovery package's spatie/php-structure-discoverer come with pollora/discovery and the framework.
    ->ignoreErrorsOnPackages(['illuminate/collections', 'spatie/php-structure-discoverer'], [ErrorType::SHADOW_DEPENDENCY])
    // ScheduleFake is a testing helper: it asserts with PHPUnit, which every project that tests has.
    ->ignoreErrorsOnPackage('phpunit/phpunit', [ErrorType::SHADOW_DEPENDENCY]);
