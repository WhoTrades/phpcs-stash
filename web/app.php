<?php
/**
 * @author Artem Naumenko
 * phpcs-stash app entry point. Accepts branch analysis requests.
 * Looks for updated pull-requests, analyses updated code,
 * and comments code that contains errors.
 */
require_once(__DIR__ . '/../vendor/autoload.php');

$env = getenv('APP_ENV') ?: 'prod';

$app = new Silex\Application();
$app->register(new Igorw\Silex\ConfigServiceProvider(__DIR__ . "/../config/$env.yml"));
$app->register(new Silex\Provider\MonologServiceProvider(), [
    'monolog.logfile' => __DIR__ . '/log/app.log',
]);

$app['monolog.phpcs'] = function ($app) {

    $dir = $app['monolog.phpcs.dir'];
    $log = new $app['monolog.logger.class']('phpcs');

    $log->pushHandler(
        new \Monolog\Handler\StreamHandler(
            __DIR__ . '/../' . $dir . '/info.' . date("Y-m-d").".log",
            $app['monolog.phpcs.info.level']
        )
    );

    $log->pushHandler(
        new \Monolog\Handler\StreamHandler(
            __DIR__ . '/../' . $dir . '/error.' . date("Y-m-d").".log",
            $app['monolog.phpcs.error.level']
        )
    );

    $log->pushHandler(
        new \Monolog\Handler\BrowserConsoleHandler()
    );

    return $log;
};

$app['phpcs.stash'] = function($app) {
    return new \PhpCsStash\Core($app['stash'], $app['monolog.phpcs'], $app['checker.factory']);
};

$app['checker.factory'] = function($app) {
    $type = $app['checker.type'];
    if ($type === 'phpcs') {
        return new \PhpCsStash\Checker\PhpCs($app['monolog.phpcs'], $app['checker.phpcs']);
    } elseif ($type === 'cpp') {
        return new \PhpCsStash\Checker\Cpp($app['monolog.phpcs'], $app['checker.phpcs']);
    }

    throw new \PhpCsStash\Exception\Runtime("Unknown checker type");
};

$app->get('/webhook/{branch}/{slug}/{repo}', function ($branch, $slug, $repo) use ($app) {
    $service = $app['phpcs.stash'];
    $service->runSync($branch, $slug, $repo);
})->assert('branch', '\w+')->assert('slug', '\w+')->assert('repo', '\w+');

$app->run();