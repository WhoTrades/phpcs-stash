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
            $stashConfig['password']
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
     */
    public function runSync($branch, $slug, $repo)
    {
        if (empty($branch) || empty($repo) || empty($slug)) {
            $this->getLogger()->warning("Invalid request: empty slug or branch or repo", $_GET);
            throw new \InvalidArgumentException("Invalid request: empty slug or branch or repo");
        }

        $requestProcessor = new RequestProcessor(
            $this->getStash(),
            $this->getLogger(),
            $this->getConfigSection('type'),
            $this->getConfigSection('phpcs'),
            $this->getConfigSection('cpp')
        );

        return $requestProcessor->processRequest($slug, $repo, $branch);
    }
}