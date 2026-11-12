<?php

declare(strict_types=1);

/*
 * Prepended (auto_prepend_file) to the temporary Reverb started by
 * `php artisan uplink:bench --profile`. Samples the whole process with
 * Excimer (wall clock, 1 ms) and rewrites the profile every second, so the
 * files are complete whenever the bench stops the server.
 *
 * Env: EXCIMER_OUTPUT, path prefix of the files written:
 * <prefix>.speedscope.json (open in https://www.speedscope.app) and
 * <prefix>.functions.json (per function: self and inclusive samples).
 */

if (! extension_loaded('excimer') || getenv('EXCIMER_OUTPUT') === false) {
    return;
}

$GLOBALS['__uplinkBenchProfiler'] = new ExcimerProfiler;
$GLOBALS['__uplinkBenchProfiler']->setPeriod(0.001);
$GLOBALS['__uplinkBenchProfiler']->setEventType(EXCIMER_REAL);
$GLOBALS['__uplinkBenchProfiler']->start();

$GLOBALS['__uplinkBenchFlush'] = new ExcimerTimer;
// setInterval is only the first expiry; setPeriod makes the timer repeat.
$GLOBALS['__uplinkBenchFlush']->setInterval(1.0);
$GLOBALS['__uplinkBenchFlush']->setPeriod(1.0);
$GLOBALS['__uplinkBenchFlush']->setCallback(static function (): void {
    $prefix = (string) getenv('EXCIMER_OUTPUT');
    $log = $GLOBALS['__uplinkBenchProfiler']->getLog();

    file_put_contents($prefix.'.speedscope.json', json_encode($log->getSpeedscopeData()));
    file_put_contents($prefix.'.functions.json', json_encode($log->aggregateByFunction()));
});
$GLOBALS['__uplinkBenchFlush']->start();
