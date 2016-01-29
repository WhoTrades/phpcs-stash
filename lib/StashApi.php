<?php
/**
 * @author Artem Naumenko
 *
 * Класс для интеграции с atlassian stash. Реализует базовую функциональность API
 * @see https://developer.atlassian.com/static/rest/stash/3.11.3/stash-rest.html
 */
namespace PhpCsStash;

use GuzzleHttp\Client;
use Monolog\Logger;

/**
 * Class StashApi
 * @package PhpCsStash
 */
class StashApi
{
    const HTTP_TIMEOUT = 90;

    /** @var Client */
    private $httpClient;

    /** @var Logger */
    private $logger;

    private $username;

    /**
     * StashApi constructor.
     * @param Logger $logger   объект для журналирования
     * @param string $url      ссылка на стеш со слешом на конце. Например: http://atlassian-stash.com/
     * @param string $user     пользователь, от имени которого будут делаться запросы
     * @param string $password пароль пользователя
     */
    public function __construct(Logger $logger, $url, $user, $password)
    {
        $this->username = $user;
        $this->logger = $logger;

        $config = [
            'base_url' => "{$url}rest/api/1.0/",
            'defaults' => [
                'timeout' => self::HTTP_TIMEOUT,
                'headers' => [
                    'Content-type' => 'application/json',
                ],
                'allow_redirects' => true,
                'auth' => [$user, $password],
            ],
        ];
        $this->httpClient = new Client($config);
    }

    /**
     * Возвращает имя текущего пользователя
     * @return string
     */
    public function getUserName()
    {
        return $this->username;
    }

    /**
     * Возвращает содержимое файла в данной ветке
     *
     * @param string $slug
     * @param string $repo
     * @param string $ref
     * @param string $filename
     * @return string
     */
    public function getFileContent($slug, $repo, $ref, $filename)
    {
        $content = (string) $this->httpClient->get("/projects/$slug/repos/$repo/browse/$filename?at=$ref&raw")->getBody();

        return $content;
    }

    /**
     * Возвращает содержимое файла в данной ветке
     *
     * @param string $slug
     * @param string $repo
     * @param int    $pullRequestId
     * @return array
     *
     * @see https://developer.atlassian.com/static/rest/stash/3.11.3/stash-rest.html#idp992528
     */
    public function getPullRequestDiffs($slug, $repo, $pullRequestId)
    {
        return $this->sendRequest("projects/$slug/repos/$repo/pull-requests/$pullRequestId/diff", "GET", []);
    }

    /**
     * @param string $slug
     * @param string $repo
     * @param int    $pullRequestId
     * @param string $filename
     *
     * @return array
     *
     * @see https://developer.atlassian.com/static/rest/stash/3.11.3/stash-rest.html#idp36368
     */
    public function getPullRequestComments($slug, $repo, $pullRequestId, $filename)
    {
        return $this->sendRequest("projects/$slug/repos/$repo/pull-requests/$pullRequestId/comments", "GET", [
            'path' => $filename,
            'limit' => 1000,
        ]);
    }

    /**
     * @param string $slug
     * @param string $repo
     * @param int    $pullRequestId
     * @param string $filename
     * @param int    $line
     * @param string $text
     * @return array
     * @see https://developer.atlassian.com/static/rest/stash/3.11.3/stash-rest.html#idp895840
     */
    public function addPullRequestComment($slug, $repo, $pullRequestId, $filename, $line, $text)
    {
        $anchor = [
            "line" => $line,
            "lineType" => "ADDED",
            "fileType" => "TO",
            'path' => $filename,
            'srcPath' => $filename,
        ];

        return $this->sendRequest("projects/$slug/repos/$repo/pull-requests/$pullRequestId/comments", "POST", [
            'text' => $text,
            'anchor' => $anchor,
        ]);
    }

    /**
     * @param string $slug
     * @param string $repo
     * @param int    $pullRequestId
     * @param int    $commentId
     * @param int    $version
     * @return array
     * @see https://developer.atlassian.com/static/rest/stash/3.11.3/stash-rest.html#idp895840
     */
    public function deletePullRequestComment($slug, $repo, $pullRequestId, $version, $commentId)
    {
        return $this->sendRequest(
            "projects/$slug/repos/$repo/pull-requests/$pullRequestId/comments/$commentId/?version=$version",
            "DELETE",
            []
        );
    }

    /**
     * @param string $slug
     * @param string $repo
     * @param int    $pullRequestId
     * @param int    $commentId
     * @param int    $version
     * @param string $text
     * @return array
     *
     * @see https://developer.atlassian.com/static/rest/stash/3.11.3/stash-rest.html#idp1467264
     */
    public function updatePullRequestComment($slug, $repo, $pullRequestId, $commentId, $version, $text)
    {
        $request = [
            'version' => $version,
            'text' => $text,
        ];

        return $this->sendRequest(
            "projects/$slug/repos/$repo/pull-requests/$pullRequestId/comments/$commentId",
            "PUT",
            $request
        );
    }

    /**
     * Максмимальное количество пул реквестов - 100. Расчитываю на то что не будет более 100 пулреквестов на одну
     * фичевую ветку :)
     *
     * @param string $slug
     * @param string $repo
     * @param string $ref
     * @return array
     * @see https://developer.atlassian.com/static/rest/stash/3.11.3/stash-rest.html#idp992528
     */
    public function getPullRequestsByBranch($slug, $repo, $ref)
    {
        $query = [
            "state" => "open",
            "at" => $ref,
            "direction" => "OUTGOING",
            "limit" => 100,
        ];

        return $this->sendRequest("projects/$slug/repos/$repo/pull-requests", "GET", $query);
    }

    private function sendRequest($url, $method, $request)
    {
        if (strtoupper($method) == 'GET') {
            $reply = $this->httpClient->get($url, ['query' => $request]);
        } else {
            $reply = $this->httpClient->send(
                $this->httpClient->createRequest($method, $url, ['body' => json_encode($request)])
            );
        }

        $json = (string) $reply->getBody();

        //an: пустой ответ - значит все хорошо
        if (empty($json)) {
            return true;
        }

        $data = json_decode($json, true);

        if ($data === null && $data != 'null') {
            $this->logger->addError("Invalid json received", [
                'url' => $url,
                'method' => $method,
                'request' => $request,
                'reply' => $json,
            ]);

            throw new \Exception('invalid_json_received');
        }

        return $data;
    }
}
