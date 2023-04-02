<?php

declare(strict_types=1);

namespace ComposerInstaller;

use Composer\Composer;
use Composer\Config;
use Composer\Installer\InstallationManager;
use Composer\IO\IOInterface;
use Composer\Package\BasePackage;
use Composer\Package\Package;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Repository\RepositoryManager;
use Composer\Script\Event;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamDirectory;
use PgFramework\ComposerInstaller\ModuleInstaller;
use PHPUnit\Framework\MockObject\Exception;
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
     * @throws Exception
     */
    public function setUp(): void
    {
        parent::setUp();

        $this->projectRoot = vfsStream::setup('project', 0777, $this->structure);
        $this->path = vfsStream::url('project');

        /** @var IOInterface&MockObject $io */
        $io = $this->createMock(IOInterface::class);
        $this->io = $io;

        $mockComposer = $this->createMock(Composer::class);
        $this->mockComposer = $mockComposer;

        $mockConfig = $this->createMock(Config::class);
        $this->mockConfig = $mockConfig;
        $this->mockConfig
            ->method('get')
            ->with('vendor-dir')
            ->willReturn($this->path . '/vendor');
        $this->mockComposer->method('getConfig')->willReturn($this->mockConfig);

        $mockRepositoryManager = $this->createMock(RepositoryManager::class);
        $this->mockRepositoryManager = $mockRepositoryManager;
        $this->mockComposer->method('getRepositoryManager')->willReturn($this->mockRepositoryManager);

        $mockInstalledRepository = $this->createMock(InstalledRepositoryInterface::class);
        $this->mockInstalledRepository = $mockInstalledRepository;
        $this->mockRepositoryManager
            ->method('getLocalRepository')
            ->willReturn($this->mockInstalledRepository);

        $mockPlugin = $this->getMockBuilder(ModuleInstaller::class)->getMock();
        $this->mockPlugin = $mockPlugin;

        $installationManager = $this->createMock(InstallationManager::class);
        $this->installationManager = $installationManager;
        $this->mockComposer->method('getInstallationManager')->willReturn($this->installationManager);

        $this->plugin = new ModuleInstaller();
        // LOCK_EX not working with vfsStream
        $this->plugin->setWriteLockEx(0);
        $this->plugin->activate($this->mockComposer, $this->io);

        // Init config file
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
    }

    protected function createPhpFile(string $path, string $content): string
    {
        $path = $this->path . '/' . $path;
        file_put_contents($path, $content);
        return $path;
    }

    public static function getIoMessageCallback(array $messages): \Closure
    {
        return function ($arg) use ($messages) {
            if (is_string($arg) && in_array($arg, $messages)) {
                return true;
            }
            return false;
        };
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

        $messages = [
            '<info>  Found pg-module type package: pg-framework/router</info>',
            '<info>  Found pg-module type package: pg-framework/auth</info>',
        ];
        $this->io
            ->expects(self::exactly(2))
            ->method('write')
            ->with(self::callback(self::getIoMessageCallback($messages)));

        $return = $this->plugin->findModulesPackages($packages);

        $expected = [
            $plugin1,
            $plugin2,
        ];
        $this->assertSame($expected, $return, 'Composer-loaded module should be listed');
    }

    /**
     * @throws Exception
     */
    public function testPluginAbortEarlyWithModulesPackagesEmpty()
    {
        $packages = $this->getNoPgModulePackages();
        $event = $this->createMock(Event::class);
        $this->mockInstalledRepository
            ->method('getPackages')
            ->willReturn($packages);
        $messages = [
            '<info>Search pg-modules packages</info>',
            '<info>pg-modules packages not found, abort</info>',
        ];
        $this->io
            ->expects(self::exactly(2))
            ->method('write')
            ->with(self::callback(self::getIoMessageCallback($messages)));
        $this->plugin->postAutoloadDump($event);
    }

    protected function getGoodPackages(): array
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

        return [
            $plugin1,
            new Package('SomethingElse', '1.0', '1.0'),
            $plugin2,
        ];
    }

    /**
     * @throws Exception
     */
    public function testPluginAbortEarlyWithModulesEmpty()
    {
        $packages = $this->getGoodPackages();
        $this->installationManager
            ->method('getInstallPath')
            ->willReturnCallback(
                function (BasePackage $package) {
                    return $this->path .
                        '/src/' .
                        $package->getPrettyName() .
                        $package->getTargetDir();
                }
            );
        $event = $this->createMock(Event::class);
        $this->mockInstalledRepository
            ->method('getPackages')
            ->willReturn($packages);
        $messages = [
            '<info>Search pg-modules packages</info>',
            '<info>  Found pg-module type package: pg-framework/router</info>',
            '<info>  Found pg-module type package: pg-framework/auth</info>',
            '<info>pg-modules not found in packages, abort</info>',
        ];
        $this->io
            ->expects(self::exactly(4))
            ->method('write')
            ->with(self::callback(self::getIoMessageCallback($messages)));
        $this->plugin->postAutoloadDump($event);
    }

    public function testFindModulesPackagesEmpty()
    {
        $packages = $this->getNoPgModulePackages();
        $this->io->expects(self::never())->method('write');
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

    protected function getModuleClassTemplate(): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace %s;

use PgFramework\Module;

class %s extends Module
{
}

PHP;
    }

    public function testFindModulesClass()
    {
        $expected = [];
        $content = $this->getModuleClassTemplate();

        $routerNs = 'Router';
        $routerClass = 'RouterModule';
        $expected[$routerNs . '\\' . $routerClass] = $routerClass;
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

        $authNs = 'Auth\Auth';
        $authClass = 'AuthModule';
        $expected[$authNs . '\\' . $authClass] = $authClass;
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
        $expected[$fakeNs . '\\' . $fakeClass] = $fakeClass;
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

        $messages = [
            "<info>      Found pg-module: $routerClass</info>",
            "<info>      Found pg-module: $authClass</info>",
            "<info>      Found pg-module: $fakeClass</info>",
        ];
        $this->io
            ->expects(self::exactly(count($packages)))
            ->method('write')
            ->with(self::callback(self::getIoMessageCallback($messages)));
        $modules = $this->plugin->findModulesClass($packages);
        $this->assertSame($expected, $modules);
    }

    public function testGetModuleClass()
    {
        $content = $this->getModuleClassTemplate();
        $this->createPhpFile(
            'vendor/pgframework/auth/src/Auth/AuthModule.php',
            sprintf($content, 'Auth\\Auth', 'AuthModule')
        );
        $this->createPhpFile(
            'vendor/pgframework/fake-module/src/FakeModule.php',
            sprintf($content, 'FakeModule', 'FakeModule')
        );
        $files = [
            $this->path . '/vendor/pgframework/auth/src/Auth/AuthModule.php',
            $this->path . '/vendor/pgframework/auth/src/config.php',
            $this->path . '/vendor/pgframework/fake-module/src/FakeModule.php'
        ];

        $messages = [
            "<info>      Found pg-module: AuthModule</info>",
            "<info>      Found pg-module: FakeModule</info>",
        ];
        $this->io
            ->expects(self::exactly(2))
            ->method('write')
            ->with(self::callback(self::getIoMessageCallback($messages)));
        $modules = $this->plugin->getModulesClass($files);
        $this->assertCount(2, $modules);
        $this->assertArrayHasKey('Auth\Auth\AuthModule', $modules);
        $this->assertArrayHasKey('FakeModule\FakeModule', $modules);
        $this->assertContains('AuthModule', $modules);
        $this->assertContains('FakeModule', $modules);
    }

    public function testWriteConfigFile()
    {
        $configFile = $this->path . '/src/Bootstrap/PgFramework.php';
        $modules = [
            'Router\RouterModule' => 'RouterModule',
            'Auth\Auth\UserModule' => 'UserModule',
            'Auth\Auth\AuthModule' => 'AuthModule',
            'FakeModule\FakeModule' => 'FakeModule',
        ];

        $expected = $this->getFullContentConfigFile();

        $messages = [
            '<info>Write module RouterModule in config file</info>',
            '<info>Write module UserModule in config file</info>',
            '<info>Write module AuthModule in config file</info>',
            '<info>Write module FakeModule in config file</info>',
        ];
        $this->io
            ->expects(self::exactly(4))
            ->method('write')
            ->with(self::callback(self::getIoMessageCallback($messages)));
        $return = $this->plugin->writeConfigFile($configFile, $modules);
        $this->assertTrue(true === $return);
        $content = file_get_contents($configFile);
        $this->assertIsString($content);
        $this->assertStringContainsString(
            str_replace(["\t", "\n", "\r", ' '], '', $expected),
            str_replace(["\t", "\n", "\r", ' '], '', $content)
        );
    }

    public function testWriteConfigFileSkipModuleExists()
    {
        $configFile = $this->path . '/src/Bootstrap/PgFramework.php';
        $modules = [
            'Router\RouterModule' => 'RouterModule',
            'Auth\Auth\UserModule' => 'UserModule',
            'Auth\Auth\AuthModule' => 'AuthModule',
            'FakeModule\FakeModule' => 'FakeModule',
        ];

        $expected = <<<PHP
<?php

/** This file is auto generated, do not edit */

declare(strict_types=1);

use Router\RouterModule;
use Auth\Auth\UserModule;

return [
    'modules' => [
               RouterModule::class,
               UserModule::class,
    ]
];

PHP;
        $this->createPhpFile('src/Bootstrap/PgFramework.php', $expected);
        $messages = [
            "<info>Module RouterModule already exist in config file</info>",
            "<info>Module UserModule already exist in config file</info>",
            "<info>Write module AuthModule in config file</info>",
            "<info>Write module FakeModule in config file</info>",
        ];
        $this->io
            ->expects(self::exactly(4))
            ->method('write')
            ->with(self::callback(self::getIoMessageCallback($messages)));
        $return = $this->plugin->writeConfigFile($configFile, $modules);
        $this->assertTrue(true === $return);
        $content = file_get_contents($configFile);
        $this->assertIsString($content);
        $this->assertStringContainsString(
            str_replace(["\t", "\n", "\r", ' '], '', $this->getFullContentConfigFile()),
            str_replace(["\t", "\n", "\r", ' '], '', $content)
        );
    }

    public function testWriteConfigFileDoNothing()
    {
        $configFile = $this->path . '/src/Bootstrap/PgFramework.php';
        $modules = [
            'Router\RouterModule' => 'RouterModule',
            'Auth\Auth\UserModule' => 'UserModule',
            'Auth\Auth\AuthModule' => 'AuthModule',
            'FakeModule\FakeModule' => 'FakeModule',
        ];

        $expected = $this->getFullContentConfigFile();
        $this->createPhpFile('src/Bootstrap/PgFramework.php', $expected);
        $messages = [
            "<info>Module RouterModule already exist in config file</info>",
            "<info>Module UserModule already exist in config file</info>",
            "<info>Module AuthModule already exist in config file</info>",
            "<info>Module FakeModule already exist in config file</info>",
            '<info>Nothing to update in config file.</info>',
        ];
        $this->io
            ->expects(self::exactly(5))
            ->method('write')
            ->with(self::callback(self::getIoMessageCallback($messages)));
        $return = $this->plugin->writeConfigFile($configFile, $modules);
        $this->assertTrue(false === $return);
    }

    public function testWriteConfigFileWithoutConfigFile()
    {
        unlink($this->path . '/src/Bootstrap/PgFramework.php');
        $this->assertFileDoesNotExist($this->path . '/src/Bootstrap/PgFramework.php');

        $configFile = $this->path . '/src/Bootstrap/PgFramework.php';
        $modules = [
            'Router\RouterModule' => 'RouterModule',
            'Auth\Auth\UserModule' => 'UserModule',
            'Auth\Auth\AuthModule' => 'AuthModule',
            'FakeModule\FakeModule' => 'FakeModule',
        ];

        $expected = $this->getFullContentConfigFile();

        $this->io->expects(self::exactly(5))->method('write');
        $return = $this->plugin->writeConfigFile($configFile, $modules);
        $this->assertFileExists($this->path . '/src/Bootstrap/PgFramework.php');
        $this->assertTrue(true === $return);
        $content = file_get_contents($configFile);
        $this->assertIsString($content);
        $this->assertStringContainsString(
            str_replace(["\t", "\n", "\r", ' '], '', $expected),
            str_replace(["\t", "\n", "\r", ' '], '', $content)
        );
    }

    protected function getFullContentConfigFile(): string
    {
        return <<<PHP
<?php

/** This file is auto generated, do not edit */

declare(strict_types=1);

use Router\RouterModule;
use Auth\Auth\UserModule;
use Auth\Auth\AuthModule;
use FakeModule\FakeModule;

return [
    'modules' => [
               RouterModule::class,
               UserModule::class,
               AuthModule::class,
               FakeModule::class,
    ]
];

PHP;
    }
}