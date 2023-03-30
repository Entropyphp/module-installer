<?php

declare(strict_types=1);

namespace ComposerInstaller;

use Composer\Composer;
use Composer\Config;
use Composer\Installer\InstallationManager;
use Composer\IO\IOInterface;
use Composer\Package\BasePackage;
use Composer\Package\Package;
use Composer\Repository\RepositoryManager;
use Composer\Util\HttpDownloader;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamDirectory;
use PgFramework\ComposerInstaller\ModuleInstaller;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ModuleInstallerTest extends TestCase
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
     * @var vfsStreamDirectory
     */
    protected vfsStreamDirectory $projectRoot;

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
        'src/Home',
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

        $this->package = new Package('pg-framework/RouterModule', '1.0', '1.0');
        $this->package->setType('pg-module');

        $this->projectRoot = vfsStream::setup('project');
        $this->path = vfsStream::url('project');

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
        $this->createModulesConfig('/src/Bootstrap/pgFramework.php', $content);

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

        $mockPlugin = $this->getMockBuilder(ModuleInstaller::class)->getMock();
        $this->mockPlugin = $mockPlugin;

        $installationManager = $this->getMockBuilder(InstallationManager::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->installationManager = $installationManager;

        $this->composer->setInstallationManager($installationManager);

        $this->plugin = new ModuleInstaller();
        $this->plugin->activate($this->composer, $this->io);
    }

    public function tearDown(): void
    {
        parent::tearDown();
    }

    protected function createModulesConfig(string $path, string $content)
    {
        vfsStream::newDirectory(dirname($path))
            ->at($this->projectRoot);
        vfsStream::newFile($path)
            ->at($this->projectRoot)
            ->setContent($content);
    }

    protected function createModuleClass(string $path, string $content)
    {
        vfsStream::newDirectory(dirname($path))
            ->at($this->projectRoot);
        vfsStream::newFile($path)
            ->at($this->projectRoot)
            ->setContent($content);
    }

    public function testGetSubscribedEvents()
    {
        $expected = [
            'post-autoload-dump' => 'postAutoloadDump',
        ];

        $this->assertSame($expected, $this->plugin->getSubscribedEvents());
    }

    public function testGetConfigFilePath()
    {
        $path = $this->plugin->getConfigFile($this->path);
        $this->assertFileExists(dirname($path));
    }

    public function testFindModulesPackages()
    {
        $plugin1 = new Package('pg-framework/router', '1.0', '1.0');
        $plugin1->setType('pg-module');
        $plugin1->setAutoload([
            'psr-4' => [
                'Router' => 'src/',
            ],
        ]);

        $plugin2 = new Package('pg-framework/auth', '1.0', '1.0');
        $plugin2->setType('pg-module');
        $plugin2->setAutoload([
            'psr-4' => [
                'Auth' => 'src/',
            ],
        ]);

        $packages = [
            $plugin1,
            new Package('SomethingElse', '1.0', '1.0'),
            $plugin2,
        ];

        $return = $this->plugin->findModulesPackages($packages);

        $expected = [
            $plugin1,
            $plugin2,
        ];
        $this->assertSame($expected, $return, 'Composer-loaded module should be listed');
    }

    public function testFindModulesPackagesEmpty()
    {
        $plugin1 = new Package('pg-framework/router', '1.0', '1.0');
        $plugin1->setType('library');
        $plugin1->setAutoload([
            'psr-4' => [
                'Router' => 'src/',
            ],
        ]);

        $plugin2 = new Package('pg-framework/auth', '1.0', '1.0');
        $plugin2->setType('library');
        $plugin2->setAutoload([
            'psr-4' => [
                'Auth' => 'src/',
            ],
        ]);

        $packages = [
            $plugin1,
            new Package('SomethingElse', '1.0', '1.0'),
            $plugin2,
        ];

        $return = $this->plugin->findModulesPackages($packages);
        $this->assertSame([], $return);
        $this->io
            ->expects(self::never())
            ->method('write');
    }

    public function testFindModulesClass()
    {
        $plugin1 = new Package('pgframework/router', '1.0', '1.0');
        $plugin1->setType('pg-module');
        $plugin1->setAutoload([
            'psr-4' => [
                'Router' => 'src/',
            ],
        ]);

        $content = <<<PHP

PHP;

        $this->createModuleClass('vendor/pgframework/router/src/RouterModule', $content);

        $plugin2 = new Package('pgframework/auth', '1.0', '1.0');
        $plugin2->setType('pg-module');
        $plugin2->setAutoload([
            'psr-4' => [
                'Auth' => 'src/',
            ],
        ]);

        $plugin3 = new Package('pg-framework/fake-module', '1.0', '1.0');
        $plugin3->setType('pg-module');
        $plugin3->setAutoload([
            'psr-4' => [
                'FakeModule' => 'src/',
            ],
        ]);

        $packages = [
            $plugin1,
            $plugin2,
            $plugin3,
        ];

        $this->installationManager
            ->method('getInstallPath')
            ->willReturnCallback(
                function (BasePackage $package) {
                    return $this->path . '/vendor' . '/' . $package->getPrettyName() . $package->getTargetDir();
                }
            );

        $modules = $this->plugin->findModulesClass($packages);
    }

}