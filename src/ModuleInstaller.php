<?php

declare(strict_types=1);

namespace PgFramework\ComposerInstaller;

use CallbackFilterIterator;
use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Package\BasePackage;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class ModuleInstaller implements
    EventSubscriberInterface,
    PluginInterface
{
    private Composer $composer;
    private IOInterface $io;
    private array $modules = [];

    /**
     * Called whenever composer (re)generates the autoloader.
     *
     * Recreates PgFramework Module path map, based on composer information
     * and available app plugins.
     *
     * @param  Event $event Composer's event object.
     * @return void
     */
    public static function run(Event $event): void
    {
        $composer = $event->getComposer();
        $io = $event->getIO();

        $instance = new static();
        $instance->composer = $composer;
        $instance->io = $io;
        $instance->postAutoloadDump($event);
    }

    /**
     * @inheritDoc
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;
    }

    /**
     * @inheritDoc
     */
    public function deactivate(Composer $composer, IOInterface $io)
    {
    }

    /**
     * @inheritDoc
     */
    public function uninstall(Composer $composer, IOInterface $io)
    {
    }

    /**
     * @inheritDoc
     */
    public static function getSubscribedEvents(): array
    {
        return [
            ScriptEvents::POST_AUTOLOAD_DUMP => 'postAutoloadDump',
        ];
    }

    /**
     * Called whenever composer (re)generates the autoloader.
     *
     * Recreates PgFramework Module path map, based on composer information
     * and available app plugins.
     *
     * @param  Event $event Composer's event object.
     * @return void
     */
    public function postAutoloadDump(Event $event): void
    {
        $config = $this->composer->getConfig();
        $vendorDir = $config->get('vendor-dir');
        $projectDir = dirname($vendorDir);

        $packages = $this->composer->getRepositoryManager()->getLocalRepository()->getPackages();
        $this->io->write('<info>Search pg-modules packages</info>');
        $this->findModulePackage($packages);

        $configFile = $this->getConfigFile($projectDir);
        $this->writeConfigFile($configFile);
    }

    /**
     * @param  BasePackage[] $packages
     * @return array
     */
    protected function findModulePackage(array $packages): array
    {
        $modules = [];
        foreach ($packages as $package) {
            if ($package->getType() === 'pg-module') {
                $this->io->write(
                    sprintf(
                        '<info>  Found pg-module type package: %s</info>',
                        $package->getPrettyName()
                    )
                );
                $path = $this->composer->getInstallationManager()->getInstallPath($package);
                $modules = $this->findModuleClass($package, $path);
            }
        }
        return $modules;
    }

    protected function findModuleClass(BasePackage $package, string $packagePath): array
    {
        $modules = [];
        $autoload = $package->getAutoload();
        foreach ($autoload as $type => $pathMap) {
            if ($type !== 'psr-4') {
                continue;
            }

            $paths = $this->mapNamespacePaths($pathMap, $packagePath);
            foreach ($paths as $path) {
                $files = $this->getPhpFile($path);
                if (!empty($files)) {
                    $modules = $this->getModulesClass($files);
                }
            }
        }
        return $modules;
    }

    protected function mapNamespacePaths(array $pathMap, string $packagePath): array
    {
        $result = [];
        foreach ($pathMap as $namespace => $paths) {
            $paths = array_values((array)$paths);
            foreach ($paths as $path) {
                assert(is_string($namespace));
                $src = sprintf(
                    '%s/%s',
                    $packagePath,
                    $path
                );
                $result[] = [rtrim($namespace, '\\') => $src];
            }
        }
        return $result;
    }

    protected function getPhpFile(array $result): array
    {
        $files = [];
        foreach ($result as $dir) {
            $files = $this->getFiles($dir);
        }
        return $files;
    }

    public function getFiles(string $path, string $ext = 'php', ?string $exclude = null): array
    {
        // from https://stackoverflow.com/a/41636321
        return iterator_to_array(
            new CallbackFilterIterator(
                new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator(
                        $path,
                        FilesystemIterator::FOLLOW_SYMLINKS | FilesystemIterator::SKIP_DOTS
                    )
                ),
                function (SplFileInfo $file) use ($ext, $exclude) {
                    return $file->isFile() &&
                        (!str_starts_with($file->getBasename(), '.') &&
                        null !== $exclude ?
                            !(stripos($file->getBasename(), $exclude)) :
                            str_ends_with($file->getFilename(), '.' . $ext));
                }
            )
        );
    }

    protected function getModulesClass(array $files): array
    {
        /**
 * @var SplFileInfo $file
*/
        foreach ($files as $file) {
            $content = file_get_contents((string)$file);
            if (
                preg_match(
                    '/namespace\s+(\S+)\s*;[\s\W\w]+class\s+(\S+)\s+extends\s+Module/',
                    $content,
                    $m
                )
            ) {
                $namespace = $m[1];
                $moduleName = $m[2];
                $this->modules[$namespace][$namespace . '\\' . $moduleName] = $moduleName;
                $this->io->write(
                    sprintf(
                        '<info>      Found pg-module: %s</info>',
                        $moduleName
                    )
                );
            }
        }
        return $this->modules;
    }

    protected function getConfigFile(string $projectDir): string
    {
        return $projectDir .
            DIRECTORY_SEPARATOR .
            'src' .
            DIRECTORY_SEPARATOR .
            'Bootstrap' .
            DIRECTORY_SEPARATOR .
            'PgFramework.php';
    }

    protected function writeConfigFile(string $configFile): bool
    {
        if (!is_file($configFile)) {
            return false;
        }
        $content = file_get_contents($configFile);
        $regex = '/declare\(strict_types=1\);\s+([\w\W]*)\s+return\s+\[\s+\'modules\'\s+=>\s+\[\s+([\W\w]+)\s+]\s+/';
        if (preg_match($regex, $content, $m)) {
            $writeFile = false;
            $useStr = $m[1];
            $modulesStr = $m[2];
            foreach ($this->modules as $classModules) {
                foreach ($classModules as $useStatement => $classModule) {
                    if (str_contains($content, $classModule . '::class')) {
                        $this->io->write(
                            sprintf(
                                '<info>Module %s already exist in config file</info>',
                                $classModule
                            )
                        );
                        continue;
                    }
                    $writeFile = true;
                    $modulesStr .= "\t\t$classModule::class,\n";
                    if (!$useStr) {
                        $useStr = "";
                    }
                    $useStr .= "use $useStatement;\n";

                    $this->io->write(
                        sprintf(
                            '<info>Write module %s in config file</info>',
                            $classModule
                        )
                    );
                }
            }
            if ($writeFile) {
                $modulesStr = trim($modulesStr);
                return (bool)$this->writeFile($configFile, $useStr, "\t\t" . $modulesStr);
            }
        }
        return true;
    }

    protected function writeFile(string $configFile, string $useStr, string $modulesStr): bool|int
    {
        $content = <<<php
<?php

/** This file is auto generated, do not edit */

declare(strict_types=1);

%s
return [
    'modules' => [
%s
    ]
];

php;
        return file_put_contents($configFile, sprintf($content, $useStr, $modulesStr), LOCK_EX);
    }
}
