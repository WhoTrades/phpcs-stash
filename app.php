<?php
/**
 * @author Artem Naumenko
 * Входящий скрипт для phpcs-stash. Принимает запросы на анализ изменений веток.
 * Находит пул реквесты в измененных ветках, анализирует измененный код, комментирует
 * строки с ошибками
 *
 */
require_once('vendor/autoload.php');

$core = new \PhpCsStash\Core(__DIR__."/configuration.ini");

if (empty($_SERVER['argv'][3])) {
	die("Usage: php app.php <branch> <slug> <repo>");
	exit(1);
}

var_export($core->runSync('refs/heads/' . $_SERVER['argv'][1], $_SERVER['argv'][2], $_SERVER['argv'][3]));
