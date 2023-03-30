<?php

declare(strict_types=1);

namespace ComposerInstaller;

use CallbackFilterIterator;
use Composer\Composer;
use Composer\Config;
use Composer\Installer\InstallationManager;
use Composer\IO\IOInterface;
use Composer\Package\BasePackage;
use Composer\Package\Package;
use Composer\Repository\RepositoryManager;
use Composer\Util\HttpDownloader;
use FilesystemIterator;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamDirectory;
use org\bovigo\vfs\visitor\vfsStreamStructureVisitor;
use PgFramework\ComposerInstaller\ModuleInstaller;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

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

    protected function createModulesConfig(string $path, string $content): string
    {
        $path = $this->path . '/' . $path;
        file_put_contents($path, $content);
        return $path;
    }

    protected function createModuleClass(string $path, string $content): string
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
        $this->createModulesConfig('src/Bootstrap/PgFramework.php', $content);
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
        $expect = [];

        $routerNs = 'Router';
        $routerClass = 'RouterModule';
        $expect[$routerNs] = [$routerNs . '\\' . $routerClass => $routerClass];
        $content = <<<PHP
<?php

declare(strict_types=1);

namespace %s;

use PgFramework\Module;

class %s extends Module
{
}

PHP;
        $content = sprintf($content, $routerNs, $routerClass);
        $this->createModuleClass('vendor/pgframework/router/src/RouterModule.php', $content);
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
        $expect[$authNs] = [$authNs . '\\' . $authClass => $authClass];
        $content = <<<PHP
<?php

declare(strict_types=1);

namespace %s;

use PgFramework\Module;

class %s extends Module
{
}

PHP;
        $content = sprintf($content, $authNs, $authClass);
        $this->createModuleClass('vendor/pgframework/auth/src/Auth/AuthModule.php', $content);
        $this->assertFileExists($this->path . '/vendor/pgframework/auth/src/Auth/AuthModule.php');
        $plugin2 = new Package('pgframework/auth', '1.0', '1.0');
        $plugin2->setType('pg-module');
        $plugin2->setAutoload([
            'psr-4' => [
                'Auth' => 'src/',
            ],
        ]);

        $fakeNs = 'FakeModule';
        $fakeClass = 'FakeModule';
        $expect[$fakeNs] = [$fakeNs . '\\' . $fakeClass => $fakeClass];
        $content = <<<PHP
<?php

declare(strict_types=1);

namespace %s;

use PgFramework\Module;

class %s extends Module
{
}

PHP;
        $content = sprintf($content, $fakeNs, $fakeClass);
        $this->createModuleClass('vendor/pgframework/fake-module/src/FakeModule.php', $content);
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

        $modules = $this->plugin->findModulesClass($packages);
        $this->assertSame($expect, $modules);
    }

}