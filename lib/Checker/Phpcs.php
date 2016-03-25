<?php
/**
 * @author Evgeny Sisoev
 *
 * Интерфейсы для проверки файлов разными спрособами
 */
namespace PhpCsStash\Checker;

use Monolog\Logger;

class PhpCs implements CheckerInterface
{
	 /**
     * @var checker
     */
	private $phpcs;

	/**
     * @var Logger
     */
	private $log;

    /**
	 * @param Logger   $log
     * @param array $phpcs - ['encoding' => '....', 'standard' => '...']
     */
	public function __construct(Logger $log, array $config)
	{
		$this->log = $log;
		
		if (!empty($config['installed_paths'])) {
            $GLOBALS['PHP_CODESNIFFER_CONFIG_DATA'] = array (
                'installed_paths' => str_replace(
                    '%root%',
                    dirname(__DIR__),
                    $config['installed_paths']
                ),
            );

            $this->log->debug("installed_paths=".$GLOBALS['PHP_CODESNIFFER_CONFIG_DATA']['installed_paths']);
        }
	
		$phpcs = new \PHP_CodeSniffer(
			$verbosity = 0,
			$tabWidth = 0,
			$config['encoding'],
			$interactive = false
		);

		$this->log->debug("PhpCs config", $config);

		$phpcs->initStandard($config['standard']);
		$phpcs->cli->setCommandLineValues([
			'--report=json',
			'--standard='.$config['standard'],
		]);
		
		$this->phpcs = $phpcs;
	}

	/**
	 * @param string $filename
	 * @param string $extension
     * @param string $dir
	 * @return bool
	 */
	public function shouldIgnoreFile($filename, $extension, $dir)
	{
		return $this->phpcs->shouldIgnoreFile($filename, $extension, $dir);
	}

	/**
	 * @param string $filename
	 * @param string $extension
     * @param string $fileContent
	 * @return array
	 */
    public function processFile($filename, $extension, $fileContent)
    {
        $phpCsResult = $this->phpcs->processFile($filename, $fileContent);
        $errors = $phpCsResult->getErrors();
        return $errors;
    }
}
