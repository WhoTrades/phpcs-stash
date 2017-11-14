<?php
/**
 * @author Artem Naumenko
 *
 * Ядро проекта, подгружает конфигурацию, создает объект логирования
 */
namespace PhpCsStash;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\BrowserConsoleHandler;

class Core
{
    /** @var StashApi */
    protected $stash;

    /** @var Logger */
    protected $log;

    /** @var array */
    protected $config;

    /**
     * Core constructor.
     * @param string $configFilename путь к ini файлу конфигурации
     */
    public function __construct($configFilename)
    {
        $this->config = parse_ini_file($configFilename, true);

        $this->initLogger();

        $stashConfig = $this->getConfigSection('stash');
        $this->stash = new StashApi(
            $this->getLogger(),
            $stashConfig['url'],
            $stashConfig['username'],
            $stashConfig['password'],
            $stashConfig['timeout']
        );
    }

    protected function initLogger()
    {
        $this->log = new Logger(uniqid());
        $dir = $this->config['logging']['dir']."/";

        $this->log->pushHandler(
            new StreamHandler($dir.date("Y-m-d").".log", $this->config['logging']['verbosityLog'])
        );

        $this->log->pushHandler(
            new StreamHandler($dir.date("Y-m-d").".log", $this->config['logging']['verbosityError'])
        );
		
		if (!empty($this->config['logging']['logToStdOut'])) {
		    $this->log->pushHandler(
			    new StreamHandler("php://stdout", $this->config['logging']['verbosityError'])
		    );
		}

        $this->log->pushHandler(
            new BrowserConsoleHandler()
        );
    }

    /** @return Logger */
    public function getLogger()
    {
        return $this->log;
    }

    /**
     * @return StashApi
     */
    public function getStash()
    {
        return $this->stash;
    }

    /**
     * @param string $section название секции в ini файле конфигурации
     * @return array
     */
    public function getConfigSection($section)
    {
        return $this->config[$section];
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
            $this->getLogger()->warning("Invalid request: empty slug or branch or repo", $_GET);
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
            $this->getLogger(),
            $this->getStash(),
            $this->createChecker()
        );

        return $requestProcessor;
    }

    /**
     * @return Checker\CheckerInterface
     * @throws Exception\Runtime
     */
    protected function createChecker()
    {
        $type = $this->getConfigSection('core')['type'];

        if ($type == 'phpcs') {
            return new Checker\PhpCs($this->log, $this->getConfigSection('phpcs'));
        } elseif ($type == 'cpp') {
            return new Checker\Cpp($this->log, $this->getConfigSection('cpp'));
        } else {
            throw new Exception\Runtime("Unknown checker type");
        }
    }
}
