<?php

declare(strict_types=1);

namespace ComposerInstaller;

use Composer\Composer;
use Composer\Config;
use Composer\Installer\InstallationManager;
use Composer\IO\IOInterface;
use Composer\Package\BasePackage;
use Composer\Package\Package;
use Composer\Repository\InstalledFilesystemRepository;
use Composer\Repository\RepositoryManager;
use Composer\Script\Event;
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

    protected array $structure = [
        'src' => [
            'Bootstrap' => [
                'PgFramework.php' => 'PgFramework.php'
            ]
        ],
        'vendor' => [
            'pgframework' => [
                'router' => [
                    'src' => [
                        'RouterModule.php' => 'RouterModule.php'
                    ]
                ],
                'auth' => [
                    'src' => [
                        'config.php' => "<?php\nreturn [\n];",
                        'Auth' => [
                            'AuthModule.php' => 'AuthModule.php'
                        ]
                    ]
                ],
                'fake-module' => [
                    'src' => [
                        'FakeModule.php' => 'FakeModule.php'
                    ]
                ]
            ]
        ]
    ];

    protected string $path;

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

        $this->projectRoot = vfsStream::setup('project', 0777, $this->structure);
        $this->path = vfsStream::url('project');

        $this->composer = new Composer();
        $config = new Config();
        $config->merge(['vendor-dir' => $this->path . '/vendor']);

        $this->composer->setConfig($config);

        $mockComposer = $this->getMockBuilder(Composer::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->mockComposer = $mockComposer;

        $mockConfig = $this->getMockBuilder(Config::class)->getMock();
        $this->mockConfig = $mockConfig;
        $this->mockConfig
            ->method('get')
            ->with('vendor-dir')
            ->willReturn(['vendor-dir' => $this->path . '/vendor']);
        $this->mockComposer
            ->method('getConfig')
            ->willReturn($this->mockConfig);

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
        $mockRepositoryManager = $this->getMockBuilder(RepositoryManager::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->mockRepositoryManager = $mockRepositoryManager;

        $mockInstalledRepository = $this->getMockBuilder(InstalledFilesystemRepository::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->mockInstalledRepository = $mockInstalledRepository;
        $rm->setLocalRepository($mockInstalledRepository);

        $mockPlugin = $this->getMockBuilder(ModuleInstaller::class)->getMock();
        $this->mockPlugin = $mockPlugin;

        $installationManager = $this->getMockBuilder(InstallationManager::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->installationManager = $installationManager;

        $this->composer->setInstallationManager($installationManager);

        $this->plugin = new ModuleInstaller();
        // LOCK_EX not working with vfsStream
        $this->plugin->setWriteLockEx(0);
        $this->plugin->activate($this->composer, $this->io);
    }

    protected function createPhpFile(string $path, string $content): string
    {
        $path = $this->path . '/' . $path;
        file_put_contents($path, $content);
        return $path;
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
        $content = <<<php
<?php

/** This file is auto generated, do not edit */

declare(strict_types=1);

return [
    'modules' => [
    ]
];

php;
        $this->createPhpFile('src/Bootstrap/PgFramework.php', $content);
        $path = $this->plugin->getConfigFile($this->path);
        $this->assertFileExists($path);
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

        $this->io
            ->expects(self::exactly(2))
            ->method('write');

        $return = $this->plugin->findModulesPackages($packages);

        $expected = [
            $plugin1,
            $plugin2,
        ];
        $this->assertSame($expected, $return, 'Composer-loaded module should be listed');
    }

    public function testPluginAbortEarlyWithModulesPackagesEmpty()
    {
        $packages = $this->getNoPgModulePackages();
        $this->mockInstalledRepository
            ->method('getPackages')
            ->willReturn($packages);
        $this->io
            ->expects(self::exactly(2))
            ->method('write');
        $this->plugin->postAutoloadDump(new Event('post-autoload-dump', $this->composer, $this->io));
    }

    public function testFindModulesPackagesEmpty()
    {
        $packages = $this->getNoPgModulePackages();

        $this->io
            ->expects(self::never())
            ->method('write');
        $return = $this->plugin->findModulesPackages($packages);
        $this->assertSame([], $return);
    }

    protected function getNoPgModulePackages(): array
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

        return [
            $plugin1,
            new Package('SomethingElse', '1.0', '1.0'),
            $plugin2,
        ];
    }

    public function testFindModulesClass()
    {
        $expected = [];
        $content = <<<PHP
<?php

declare(strict_types=1);

namespace %s;

use PgFramework\Module;

class %s extends Module
{
}

PHP;

        $routerNs = 'Router';
        $routerClass = 'RouterModule';
        $expected[$routerNs] = [$routerNs . '\\' . $routerClass => $routerClass];
        $this->createPhpFile(
            'vendor/pgframework/router/src/RouterModule.php',
            sprintf($content, $routerNs, $routerClass)
        );
        $this->assertFileExists($this->path . '/vendor/pgframework/router/src/RouterModule.php');
        $plugin1 = new Package('pgframework/router', '1.0', '1.0');
        $plugin1->setType('pg-module');
        $plugin1->setAutoload([
            'psr-4' => [
                'Router' => 'src/',
            ],
        ]);

        $authNs = 'Auth/Auth';
        $authClass = 'AuthModule';
        $expected[$authNs] = [$authNs . '\\' . $authClass => $authClass];
        $this->createPhpFile(
            'vendor/pgframework/auth/src/Auth/AuthModule.php',
            sprintf($content, $authNs, $authClass)
        );
        $this->assertFileExists($this->path . '/vendor/pgframework/auth/src/Auth/AuthModule.php');
        $this->assertFileExists($this->path . '/vendor/pgframework/auth/src/config.php');
        $plugin2 = new Package('pgframework/auth', '1.0', '1.0');
        $plugin2->setType('pg-module');
        $plugin2->setAutoload([
            'psr-4' => [
                'Auth' => 'src/',
            ],
        ]);

        $fakeNs = 'FakeModule';
        $fakeClass = 'FakeModule';
        $expected[$fakeNs] = [$fakeNs . '\\' . $fakeClass => $fakeClass];
        $this->createPhpFile(
            'vendor/pgframework/fake-module/src/FakeModule.php',
            sprintf($content, $fakeNs, $fakeClass)
        );
        $this->assertFileExists($this->path . '/vendor/pgframework/fake-module/src/FakeModule.php');
        $plugin3 = new Package('pgframework/fake-module', '1.0', '1.0');
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
                    return $this->path .
                        '/vendor/' .
                        $package->getPrettyName() .
                        $package->getTargetDir();
                }
            );

        $this->io
            ->expects(self::exactly(3))
            ->method('write');
        $modules = $this->plugin->findModulesClass($packages);
        $this->assertSame($expected, $modules);
    }

}