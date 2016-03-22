<?php
use PhpCsStash\Exception\StashFileInConflict;

require_once('vendor/autoload.php');

$core = new \PhpCsStash\Core(__DIR__."/configuration.ini");

$stash = $core->getStash();

//header("Content-type: text/javascript; Charset=utf8");
try {
echo $content = $stash->getFileContent('WT', 'sparta', 90, 'whotrades/app/Resources/views/blocks/page/page.js');

} catch (StashFileInConflict $e) {
    var_dump($e);
}