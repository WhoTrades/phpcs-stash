<?php
/**
 * @author Artem Naumenko
 *
 * Ядро проекта, подгружает конфигурацию, создает объект логирования
 */
namespace PhpCsStash;

use PhpCsStash\Checker\CheckerInterface;
use Psr\Log\LoggerInterface;

class Core
{
    /**
     * @var StashApi
     */
    protected $stash;

    /**
     * @var LoggerInterface
     */
    protected $log;

    /**
     * @var CheckerInterface
     */
    private $checker;

    /**
     * @param array $stashConfig
     * @param LoggerInterface $logger
     * @param CheckerInterface $checker
     */
    public function __construct(array $stashConfig, LoggerInterface $logger, CheckerInterface $checker)
    {
        $this->log = $logger;
        $this->checker = $checker;

        $this->stash = new StashApi(
            $this->log,
            $stashConfig['url'],
            $stashConfig['username'],
            $stashConfig['password'],
            $stashConfig['timeout']
        );
    }

    /**
     * Метод, который запускает работу всего приложения в синхронном режиме
     * @param string $branch
     * @param string $slug
     * @param string $repo
     * @throws \InvalidArgumentException
     * @return array
     */
    public function runSync($branch, $slug, $repo)
    {
        if (empty($branch) || empty($repo) || empty($slug)) {
            $this->log->warning("Invalid request: empty slug or branch or repo", $_GET);
            throw new \InvalidArgumentException("Invalid request: empty slug or branch or repo");
        }

        $requestProcessor = $this->createRequestProcessor();

        return $requestProcessor->processRequest($slug, $repo, $branch);
    }

    /**
     * @return RequestProcessor
     * @throws Exception\Runtime
     */
    protected function createRequestProcessor()
    {
        $requestProcessor = new RequestProcessor(
            $this->log,
            $this->stash,
            $this->checker
        );

        return $requestProcessor;
    }
}
