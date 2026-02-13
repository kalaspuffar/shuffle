<?php
namespace Shuffle\Core;

/**
 * PSR-4-style autoloader for the Shuffle namespace.
 *
 * Maps Shuffle\ namespace prefix to include/Shuffle/ directory.
 * Example: Shuffle\Model\Board -> include/Shuffle/Model/Board.php
 */
class Autoloader
{
    /** @var string Base directory for the Shuffle namespace */
    private string $baseDir;

    /**
     * @param string $baseDir Absolute path to the include/Shuffle/ directory
     */
    public function __construct(string $baseDir)
    {
        $this->baseDir = rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }

    /**
     * Register this autoloader with spl_autoload_register.
     */
    public function register(): void
    {
        spl_autoload_register([$this, 'loadClass']);
    }

    /**
     * Attempt to load a class file for the given fully-qualified class name.
     *
     * @param string $className Fully-qualified class name
     */
    public function loadClass(string $className): void
    {
        $prefix = 'Shuffle\\';

        // Only handle classes within the Shuffle namespace
        if (strncmp($prefix, $className, strlen($prefix)) !== 0) {
            return;
        }

        // Strip the namespace prefix and convert to file path
        $relativeClass = substr($className, strlen($prefix));
        $file = $this->baseDir . str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass) . '.php';

        // Silently return if file not found (allows other autoloaders to try)
        if (file_exists($file)) {
            require $file;
        }
    }
}
