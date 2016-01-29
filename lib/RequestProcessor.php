<?php
/**
 * @author Artem Naumenko
 *
 * Обработчик запросов на изменение веток
 */
namespace PhpCsStash;

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use Monolog\Logger;

/**
 * Class RequestProcessor
 * @package PhpCsStash
 */
class RequestProcessor
{
    /**
     * @var StashApi
     */
    private $stash;

    /**
     * @var Logger
     */
    private $log;

    /**
     * @var array
     */
    private $phpcsConfig;

    /**
     * @param StashApi $stash
     * @param Logger   $log
     */
    public function __construct(StashApi $stash, Logger $log, array $phpcsConfig)
    {
        $this->stash = $stash;
        $this->log = $log;
        $this->phpcsConfig = $phpcsConfig;
    }

    /**
     * @param string $slug
     * @param string $repo
     * @param string $ref
     */
    public function processRequest($slug, $repo, $ref)
    {
        $this->log->info("Processing request with slug=$slug, repo=$repo, ref=$ref");

        $pullRequests = $this->stash->getPullRequestsByBranch($slug, $repo, $ref);

        if (!empty($this->phpcsConfig['installed_paths'])) {
            $GLOBALS['PHP_CODESNIFFER_CONFIG_DATA'] = array (
                'installed_paths' => str_replace(
                    '%root%',
                    dirname(__DIR__),
                    $this->phpcsConfig['installed_paths']
                ),
            );

            $this->log->debug("installed_paths=".$GLOBALS['PHP_CODESNIFFER_CONFIG_DATA']['installed_paths']);
        }

        require_once(__DIR__."/../vendor/squizlabs/php_codesniffer/CodeSniffer.php");
        /** @var $phpcs \PHP_CodeSniffer*/
        $phpcs = new \PHP_CodeSniffer(
            $verbosity = 0,
            $tabWidth = 0,
            $this->phpcsConfig['encoding'],
            $interactive = false
        );

        $this->log->debug("PhpCs config", $this->phpcsConfig);

        $phpcs->initStandard($this->phpcsConfig['standard']);
        $phpcs->cli->setCommandLineValues([
            '--report=json',
            '--standard='.$this->phpcsConfig['standard'],
        ]);

        $this->log->info("Found {$pullRequests['size']} pull requests");
        foreach ($pullRequests['values'] as $pullRequest) {
            $this->log->info(
                "Processing pull request #{$pullRequest['id']}"
                ." {$pullRequest['fromRef']['latestChangeset']}..{$pullRequest['toRef']['latestChangeset']}"
            );

            try {
                $changes = $this->stash->getPullRequestDiffs($slug, $repo, $pullRequest['id']);
                foreach ($changes['diffs'] as $diff) {
                    $comments = [];

                    // файл был удален, нечего проверять
                    if ($diff['destination'] === null) {
                        $this->log->info("Skip processing {$diff['source']['toString']}, as it was removed");
                        continue;
                    }

                    $filename = $diff['destination']['toString'];
                    $this->log->info("Processing file $filename");
                    $affectedLines = [];

                    foreach ($diff['hunks'] as $hunk) {
                        foreach ($hunk['segments'] as $segment) {
                            if ($segment['type'] == 'CONTEXT' || $segment['type'] == 'REMOVED') {
                                continue;
                            }
                            foreach ($segment['lines'] as $line) {
                                $affectedLines[$line['destination']] = true;
                            }
                        }
                    }

                    $this->log->info("Affected lines: ".$this->visualizeNumbersToInterval(array_keys($affectedLines)));

                    $fileContent = $this->stash->getFileContent($slug, $repo, $ref, $filename);

                    $this->log->debug("File content length: ".mb_strlen($filename, $this->phpcsConfig['encoding']));

                    if (!$phpcs->shouldIgnoreFile($filename, "./")) {
                        $result = $phpcs->processFile($filename, $fileContent);
                        $errors = $result->getErrors();
                        $this->log->info("Summary errors count: ".count($errors));
                    } else {
                        $this->log->info("File is in ignore list, so no errors can be found");
                        $errors = [];
                    }

                    foreach ($errors as $line => $data) {
                        if (!isset($affectedLines[$line])) {
                            continue;
                        }

                        if (!isset($comments[$line])) {
                            $comments[$line] = "";
                        }

                        foreach ($data as $column => $errors) {
                            foreach ($errors as $error) {
                                $comments[$line] .= "{$error['message']}\n";
                            }
                        }
                        $comments[$line] = trim($comments[$line]);
                    }

                    $this->log->info("Summary errors count atfer filtration: ".count($comments));

                    $existingComments = $this->stash->getPullRequestComments(
                        $slug,
                        $repo,
                        $pullRequest['id'],
                        $filename
                    )['values'];

                    $this->log->info("Found ".count($existingComments)." comment at this pull request");

                    foreach ($existingComments as $comment) {
                        if ($comment['author']['name'] == $this->stash->getUserName()) {
                            if (!isset($comments[$comment['anchor']['line']])) {
                                // Comment exist at remote and not exists now, so remove it
                                $this->log->info("Deleting comment #{$comment['id']}", [
                                    'line' => $comment['anchor']['line'],
                                    'file' => $filename,
                                ]);

                                $this->stash->deletePullRequestComment(
                                    $slug,
                                    $repo,
                                    $pullRequest['id'],
                                    $comment['version'],
                                    $comment['id']
                                );

                            } elseif (trim($comment['text']) != trim($comments[$comment['anchor']['line']])) {
                                // Comment exist at remote and exists now, but text are different - so modify remote text
                                $this->log->info("Updating comment #{$comment['id']}", [
                                    'line' => $comment['anchor']['line'],
                                    'file' => $filename,
                                    'newText' => $comments[$comment['anchor']['line']],
                                    'oldText' => $comment['text'],
                                ]);

                                $this->stash->updatePullRequestComment(
                                    $slug,
                                    $repo,
                                    $pullRequest['id'],
                                    $comment['id'],
                                    $comment['version'],
                                    $comments[$comment['anchor']['line']]
                                );
                            }

                            unset($comments[$comment['anchor']['line']]);
                        }
                    }


                    foreach ($comments as $line => $comment) {
                        $this->log->info("Adding comment to line=$line, file=$filename", [
                            'line' => $comment,
                            'file' => $comment,
                            'text' => $comment,
                        ]);
                        $this->stash->addPullRequestComment(
                            $slug,
                            $repo,
                            $pullRequest['id'],
                            $filename,
                            $line,
                            $comment
                        );
                    }
                }

                return "OK";

            } catch (ClientException $e) {
                $this->log->critical("Error integration with stash: ".$e->getMessage(), [
                    'type' => 'client',
                    'reply' => (string) $e->getResponse()->getBody(),
                    'headers' => implode("\n", $e->getResponse()->getHeaders()),
                ]);
            } catch (ServerException $e) {
                $this->log->critical("Error integration with stash: ".$e->getMessage(), [
                    'type' => 'server',
                    'reply' => (string) $e->getResponse()->getBody(),
                    'headers' => $e->getResponse()->getHeaders(),
                ]);
            }
        }

        return 'OK';
    }

    /**
     * Преобразовывает массив чисел в удобо-читаемую строку. Например, числа 1,2,3,4,10,11 преобразует в 1-4,10,11
     * @param array $numbers - массив целых чисел
     * @return string
     */
    private function visualizeNumbersToInterval($numbers)
    {
        $result = [];
        sort($numbers);
        $prev = null;
        $first = null;
        foreach ($numbers as $val) {
            if ($prev === null) {
                $first = $val;
                $prev = $val;
                continue;
            }

            if ($prev + 1 != $val) {
                if ($first == $prev) {
                    $result[] = $first;
                } elseif ($first + 1 == $prev) {
                    $result[] = $first;
                    $result[] = $prev;
                } else {
                    $result[] = "$first-$prev";
                }
                $first = $val;
            }
            $prev = $val;
        }

        if ($first == $prev) {
            $result[] = $first;
        } else {
            $result[] = "$first-$prev";
        }

        return implode(",", $result);
    }
}
