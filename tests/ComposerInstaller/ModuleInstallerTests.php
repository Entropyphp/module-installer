<?php

declare(strict_types=1);

namespace ComposerInstaller;

use Composer\Composer;
use Composer\Config;
use Composer\IO\IOInterface;
use Composer\Package\Package;
use Composer\Repository\RepositoryManager;
use Composer\Util\HttpDownloader;
use PgFramework\ComposerInstaller\ModuleInstaller;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ModuleInstallerTests extends TestCase
{

    /**
     * @var Composer
     */
    protected Composer $composer;

    /**
     * @var Package
     */
    protected Package $package;

    /**
     * @var IOInterface&MockObject
     */
    protected $io;

    /**
     * @var ModuleInstaller
     */
    protected ModuleInstaller $plugin;

    /**
     * Directories used during tests
     *
     * @var array
     */
    protected array $testDirs = [
        '',
        'vendor',
        'src',
        'src/Bootstrap',
        'src/Fee',
        'src/Foe',
        'src/Fum',
    ];

    /**
     * setUp
     *
     * @return void
     */
    public function setUp(): void
    {
        parent::setUp();

        $this->package = new Package('pgframework/RouterModule', '1.0', '1.0');
        $this->package->setType('pg-module');

        $this->path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'module-installer-test';

        foreach ($this->testDirs as $dir) {
            if (!is_dir($this->path . '/' . $dir)) {
                mkdir($this->path . '/' . $dir);
            }
        }

        $this->composer = new Composer();
        $config = new Config();
        $config->merge([
            'vendor-dir' => $this->path . '/vendor',
        ]);

        $this->composer->setConfig($config);

        /** @var IOInterface&MockObject $io */
        $io = $this->getMockBuilder(IOInterface::class)->getMock();
        $this->io = $io;

        $httpDownloader = new HttpDownloader($this->io, $config);

        $rm = new RepositoryManager(
            $this->io,
            $config,
            $httpDownloader
        );
        $this->composer->setRepositoryManager($rm);

        $this->plugin = new ModuleInstaller();
    }

    public function tearDown(): void
    {
        parent::tearDown();

        $dirs = array_reverse($this->testDirs);

        if (is_file($this->path . '/src/Bootstrap/PgFramework.php')) {
            unlink($this->path . '/src/Bootstrap/PgFramework.php');
        }

        foreach ($dirs as $dir) {
            if (is_dir($this->path . '/' . $dir)) {
                rmdir($this->path . '/' . $dir);
            }
        }
    }

}