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

var_export($core->runSync('refs/heads/feature/WTS-3163', 'WT', 'sparta'));
