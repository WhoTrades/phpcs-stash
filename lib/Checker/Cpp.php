<?php
/**
 * @author Evgeny Sisoev
 *
 * Интерфейсы для проверки файлов разными спрособами
 */
namespace PhpCsStash\Checker;

use Monolog\Logger;

class Cpp implements CheckerInterface
{
    /**
     * @var string
     */
    private $cpplint;
    
    /**
     * @var string
     */
    private $tmpDir;
    
    /**
     * @var int
     */
    private $lineLength;
    
    /**
     * @var string
     */
    private $python27Executable;
    
    /**
     * @var Logger
     */
    private $log;

    /**
     * @param Logger $log
     * @param array $config
     */
    public function __construct(Logger $log, $config)
    {
        $this->log = $log;
        $this->cpplint = $config['cpplint'];
        $this->tmpDir = $config['tmpdir'];
        $this->python27Executable = $config['python27Executable'];
        $this->lineLength = $config['lineLength'];
    }

    /**
     * @param string $filename
     * @param string $extension
     * @param string $dir
     * @return bool
     */
    public function shouldIgnoreFile($filename, $extension, $dir)
    {
        if ($extension != "cpp" && $extension != "h" && $extension != "hpp" && $extension != "proto") {
             return true;
        }

        return false;
    }

    /**
     * @param string $errorsString
     * @return array
     */
    private function parseErrors($output)
    {
        $result = array();
        
        foreach ($output as $value) {
            if (substr($value, -1) === "]") {

                $pos1 = strpos($value, ":");
                $pos2 = strpos($value, ":  ");
                $pos3 = strpos($value, "  [");

                $line = intval(substr($value, $pos1 + 1, $pos2 - $pos1 - 1));
                $message = substr($value, $pos2 + 3, $pos3 - $pos2 - 3);

                if (!isset($result[$line])) {
                    $result[$line] = array(1 => array(0 => array("message" => $message)));
                }
                else {
                    $result[$line][1][] = array("message" => $message);
                }

            }
        }

        return $result;
    }

    /**
     * @param string $filename
     * @param string $extension
     * @param string $fileContent
     * @return array
     */
    public function processFile($filename, $extension, $fileContent)
    {
        $tempFile = "$this->tmpDir/temp.$extension";
        file_put_contents($tempFile, $fileContent);
        
        $cmd = "($this->python27Executable $this->cpplint --linelength={$this->lineLength} $tempFile 2>&1)";
           $this->log->info("Cmd: $cmd");
        exec($cmd, $output, $returnCode);

        unlink($tempFile);

        return $this->parseErrors($output);
    }
}
