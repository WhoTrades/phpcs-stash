<?php
/**
 * @author Evgeny Sisoev
 *
 * Интерфейсы для проверки файлов разными спрособами
 */
namespace PhpCsStash\Checker;

interface CheckerInterface
{
    /**
     * @param string $filename
     * @param string $extension
     * @param string $dir
     * @return bool
     */
    public function shouldIgnoreFile($filename, $extension, $dir);
    
    /**
     * @param string $filename
     * @param string $extension
     * @param string $fileContent
     * @return array
     */
    public function processFile($filename, $extension, $fileContent);
}
