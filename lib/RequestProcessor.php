<?php
/**
 * @author Artem Naumenko
 *
 * Обработчик запросов на изменение веток
 */
namespace PhpCsStash;

use PhpCsStash\Checker\CheckerInterface;
use PhpCsStash\Exception\StashJsonFailure;
use PhpCsStash\Exception\StashFileInConflict;
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
     * @var CheckerInterface
     */
    private $checker;

    /**
     * @param StashApi           $stash
     * @param Logger             $log
     * @param CheckerInterface   $checker
     */
    public function __construct(
        Logger $log,
        StashApi $stash,
        CheckerInterface $checker
    ) {
        $this->log = $log;
        $this->stash = $stash;
        $this->checker = $checker;
    }

    /**
     * @param string $slug
     * @param string $repo
     * @param string $ref
     *
     * @return array
     */
    public function processRequest($slug, $repo, $ref)
    {
        $this->log->info("Processing request with slug=$slug, repo=$repo, ref=$ref");

        $pullRequests = $this->stash->getPullRequestsByBranch($slug, $repo, $ref);

        $this->log->info("Found {$pullRequests['size']} pull requests");
        foreach ($pullRequests['values'] as $pullRequest) {
            $this->processPullRequest($slug, $repo, $pullRequest);
        }

        // No pull requests found, so no errors
        return [];
    }

    protected function processPullRequest($slug, $repo, array $pullRequest)
    {
        $this->log->info(
            "Processing pull request #{$pullRequest['id']} {$pullRequest['fromRef']['latestCommit']}..{$pullRequest['toRef']['latestCommit']}"
        );

        $result = [];

        try {
            if ($this->stash->getUserName() != $pullRequest['author']['user']['name']) {
                $this->stash->addMeToPullRequestReviewers($slug, $repo, $pullRequest['id']);
            }

            $changes = $this->stash->getPullRequestDiffs($slug, $repo, $pullRequest['id'], 0);

            foreach ($changes['diffs'] as $diff) {
                $filename = $diff['destination']['toString'];
                $errors = $this->getDiffErrors($slug, $repo, $diff, $filename, $pullRequest['id']);
                $affectedLines = $this->getDiffAffectedLines($diff);
                $comments = $this->getCommentsFilteredByAffectedLines($filename, $errors, $affectedLines);

                foreach ($comments as $line => $comment) {
                    $result[$filename][$line] = $comment;
                }

                $this->log->info("Summary errors count after filtration: " . count($comments));

                $this->actualizePullRequestComments($slug, $repo, $pullRequest['id'], $filename, $comments);
            }

            $this->removeOutdatedRobotComments($slug, $repo, $pullRequest['id']);

            if (!$result) {
                $this->stash->approvePullRequest($slug, $repo, $pullRequest['id']);
                $this->log->info("Approved pull request #{$pullRequest['id']}");
            } else {
                $this->stash->unapprovePullRequest($slug, $repo, $pullRequest['id']);
            }
        } catch (ClientException $e) {
            $this->log->critical("Error integration with stash: ".$e->getMessage(), [
                'type' => 'client',
                'reply' => (string) $e->getResponse()->getBody(),
                'headers' => $e->getResponse()->getHeaders(),
            ]);
        } catch (ServerException $e) {
            $this->log->critical("Error integration with stash: ".$e->getMessage(), [
                'type' => 'server',
                'reply' => (string) $e->getResponse()->getBody(),
                'headers' => $e->getResponse()->getHeaders(),
            ]);
        } catch (StashJsonFailure $e) {
            $this->log->error("Json failure at pull request #{$pullRequest['id']}: ".$e->getMessage());
        }

        return $result;
    }

    protected function getDiffErrors($slug, $repo, $diff, $filename, $pullRequestId)
    {
        // файл был удален, нечего проверять
        if ($diff['destination'] === null) {
            $this->log->info("Skip processing {$diff['source']['toString']}, as it was removed");
            return [];
        }

        $extension = $diff['destination']['extension'];
        $this->log->info("Processing file $filename");

        if ($this->checker->shouldIgnoreFile($filename, $extension, "./")) {
            $this->log->info("File is in ignore list, so no errors can be found");
            return [];
        }

        try {
            $fileContent = $this->stash->getFileContent($slug, $repo, $pullRequestId, $filename);
        } catch (StashFileInConflict $e) {
            $this->log->error("File $filename at pull request #$pullRequestId os in conflict state, skip code style checking");
            return [];
        } catch (StashJsonFailure $e) {
            $this->log->error("Can't get contents of $filename at pull request #$pullRequestId");
            return [];
        }

        $this->log->debug("File content length: ".mb_strlen($fileContent));

        $errors = $this->checker->processFile($filename, $extension, $fileContent);
        $this->log->info("Summary errors count: ".count($errors));

        return $errors;
    }

    /**
     * @param $diff
     * @return int[] $affectedLines - list of affected lines [12, 13, 144, 145, 146]
     */
    protected function getDiffAffectedLines($diff)
    {
        $affectedLines = [];

        foreach ($diff['hunks'] as $hunk) {
            foreach ($hunk['segments'] as $segment) {
                if ($segment['type'] == 'CONTEXT' || $segment['type'] == 'REMOVED') {
                    continue;
                }
                foreach ($segment['lines'] as $line) {
                    $affectedLines[] = $line['destination'];
                }
            }
        }

        $this->log->info("Affected lines: " . $this->visualizeNumbersToInterval(array_keys($affectedLines)));

        return $affectedLines;
    }

    /**
     * @param string $filename
     * @param array $errors
     * @param int[] $affectedLines - list of affected lines [12, 13, 144, 145, 146]
     * @return array list of comments by file line [ 12 => 'Error at line 12', 144 => 'Othr error at line 144']
     */
    protected function getCommentsFilteredByAffectedLines($filename, $errors, $affectedLines)
    {
        $comments = [];
        foreach ($errors as $line => $data) {
            if (!in_array($line, $affectedLines)) {
                continue;
            }

            if (!isset($comments[$line])) {
                $comments[$line] = [];
            }

            foreach ($data as $column => $errors) {
                foreach ($errors as $error) {
                    $comments[$line][] = "{$error['message']}\n";
                }
            }
        }

        $comments = array_map(function ($val) {
            return implode("\n", array_unique($val));
        }, $comments);

        return $comments;
    }

    protected function actualizePullRequestComments($slug, $repo, $pullRequestId, $filename, $comments)
    {
        $existingComments = $this->stash->getPullRequestComments(
            $slug,
            $repo,
            $pullRequestId,
            $filename
        )['values'];

        $this->log->info("Found ".count($existingComments)." comment at this pull request");

        foreach ($existingComments as $comment) {
            // filtering only robot comments
            if ($comment['author']['name'] != $this->stash->getUserName()) {
                continue;
            }

            if (!isset($comments[$comment['anchor']['line']])) {
                // Comment exist at remote and not exists now, so remove it
                $this->log->info("Deleting comment #{$comment['id']}", [
                    'line' => $comment['anchor']['line'],
                    'file' => $filename,
                ]);

                if (empty($comment['comments'])) {
                    $this->stash->deletePullRequestComment(
                        $slug,
                        $repo,
                        $pullRequestId,
                        $comment['version'],
                        $comment['id']
                    );
                } else {
                    //If there are replies to our comment - just strike through our message
                    //@see https://confluence.atlassian.com/display/STASH0310/Markdown+syntax+guide#Markdownsyntaxguide-Characterstyles
                    $this->stash->updatePullRequestComment(
                        $slug,
                        $repo,
                        $pullRequestId,
                        $comment['id'],
                        $comment['version'],
                        $this->getStrikeThroughedCommentText($comment["text"])
                    );
                }

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
                    $pullRequestId,
                    $comment['id'],
                    $comment['version'],
                    $comments[$comment['anchor']['line']]
                );
            }

            unset($comments[$comment['anchor']['line']]);
        }

        // all rest comments just adding to pull request
        foreach ($comments as $line => $comment) {
            $this->log->info("Adding comment to line=$line, file=$filename", [
                'line' => $comment,
                'file' => $comment,
                'text' => $comment,
            ]);
            $this->stash->addPullRequestComment(
                $slug,
                $repo,
                $pullRequestId,
                $filename,
                $line,
                $comment
            );
        }
    }

    /**
     * Strikes text
     * @see https://confluence.atlassian.com/bitbucketserver0411/markdown-syntax-guide-861180510.html?utm_campaign=in-app-help&utm_medium=in-app-help&utm_source=stash
     * @param $text
     * @return mixed
     */
    private function getStrikeThroughedCommentText($text)
    {
        return preg_replace("/^([^~\n\r].*[^~\n\r])$/", "~~$1~~", $text);
    }

    /**
     * Removes robot comments for code, which is outdated now. This comments does not returns with file comments
     * @param string $slug
     * @param string $repo
     * @param int $pullRequestId
     */
    protected function removeOutdatedRobotComments($slug, $repo, $pullRequestId)
    {
        $activities = $this->stash->getPullRequestActivities(
            $slug,
            $repo,
            $pullRequestId
        )['values'];

        foreach ($activities as $activity) {
            if ($activity['action'] != 'COMMENTED' || $activity['commentAction'] != 'ADDED') {
                continue;
            }

            if (empty($activity['commentAnchor'])) {
                continue;
            }

            if (!$activity['commentAnchor']['orphaned']) {
                $this->log->debug("Skip activity #{$activity['id']} as not orphaned");
                continue;
            }

            if ($activity['user']['name'] != $this->stash->getUserName()) {
                continue;
            }

            if (empty($activity['comment']['id'])) {
                $this->log->info("Cannot delete activity #{$activity['id']} as comment id not found");
                continue;
            }

            if (empty($activity['comment']['comments'])) {
                $this->stash->deletePullRequestComment(
                    $slug,
                    $repo,
                    $pullRequestId,
                    $activity['comment']['version'],
                    $activity['comment']['id']
                );
                $this->log->debug("Delete activity #{$activity['id']} (commentId {$activity['comment']['id']}) as orphaned");
            } else {
                //If there are replies to our comment - just strike through our message
                //@see https://confluence.atlassian.com/display/STASH0310/Markdown+syntax+guide#Markdownsyntaxguide-Characterstyles
                $this->stash->updatePullRequestComment(
                    $slug,
                    $repo,
                    $pullRequestId,
                    $activity['comment']['id'],
                    $activity['comment']['version'],
                    preg_replace("/^([^~]+)/s", "~~$1", $activity['comment']["text"])
                );
            }

        }
    }

    /**
     * Converts array of numbers to human-readable string. Example, [1,2,3,4,10,11] -> "1-4,10,11"
     * @param array $numbers - input numbers array
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
