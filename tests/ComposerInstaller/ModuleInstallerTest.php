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
        $plugin1 = $this->createPackage('pgframework/router', 'Router');
        $plugin2 = $this->createPackage('pgframework/auth', 'Auth');
        $packages = [
            $plugin1,
            new Package('SomethingElse', '1.0', '1.0'),
            $plugin2,
        ];

        $messages = [
            '<info>  Found pg-module type package: pgframework/router</info>',
            '<info>  Found pg-module type package: pgframework/auth</info>',
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
        $this->assertSame($expected, $return);
    }

    protected function createPackage(string $name, string $namespace, string $type = 'pg-module'): Package
    {
        $plugin = new Package($name, '1.0', '1.0');
        $plugin->setType($type);
        $plugin->setAutoload([
            'psr-4' => [
                $namespace => 'src/',
            ],
        ]);
        return $plugin;
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

    protected function getNoPgModulePackages(): array
    {
        $plugin1 = $this->createPackage('pg-framework/router', 'Router', 'library');
        $plugin2 = $this->createPackage('pg-framework/auth', 'Auth', 'library');

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
            '<info>  Found pg-module type package: pgframework/router</info>',
            '<info>  Found pg-module type package: pgframework/auth</info>',
            '<info>pg-modules not found in packages, abort</info>',
        ];
        $this->io
            ->expects(self::exactly(4))
            ->method('write')
            ->with(self::callback(self::getIoMessageCallback($messages)));
        $this->plugin->postAutoloadDump($event);
    }

    protected function getGoodPackages(): array
    {
        $plugin1 = $this->createPackage('pgframework/router', 'Router');
        $plugin2 = $this->createPackage('pgframework/auth', 'Auth');

        return [
            $plugin1,
            new Package('SomethingElse', '1.0', '1.0'),
            $plugin2,
        ];
    }

    /**
     * @throws Exception
     */
    public function testPostAutoloadDumpOk()
    {
        $content = $this->getModuleClassTemplate();

        $routerNs = 'Router';
        $routerClass = 'RouterModule';
        $this->createPhpFile(
            'vendor/pgframework/router/src/RouterModule.php',
            sprintf($content, $routerNs, $routerClass)
        );
        $this->assertFileExists($this->path . '/vendor/pgframework/router/src/RouterModule.php');

        $authNs = 'Auth\Auth';
        $authClass = 'AuthModule';
        $this->createPhpFile(
            'vendor/pgframework/auth/src/Auth/AuthModule.php',
            sprintf($content, $authNs, $authClass)
        );
        $this->assertFileExists($this->path . '/vendor/pgframework/auth/src/Auth/AuthModule.php');
        $this->assertFileExists($this->path . '/vendor/pgframework/auth/src/config.php');

        $packages = $this->getGoodPackages();
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
        $event = $this->createMock(Event::class);
        $this->mockInstalledRepository
            ->method('getPackages')
            ->willReturn($packages);

        $messages = [
            "<info>Search pg-modules packages</info>",
            "<info>  Found pg-module type package: pgframework/router</info>",
            "<info>  Found pg-module type package: pgframework/auth</info>",
            "<info>      Found pg-module: RouterModule</info>",
            "<info>      Found pg-module: AuthModule</info>",
            "<info>Write module RouterModule in config file</info>",
            "<info>Write module AuthModule in config file</info>",
        ];
        $this->io
            ->expects(self::exactly(7))
            ->method('write')
            ->with(self::callback(self::getIoMessageCallback($messages)));
        $this->plugin->postAutoloadDump($event);
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

    public function testFindModulesPackagesEmpty()
    {
        $packages = $this->getNoPgModulePackages();
        $this->io->expects(self::never())->method('write');
        $return = $this->plugin->findModulesPackages($packages);
        $this->assertSame([], $return);
    }

    public function testFindModulesClass()
    {
        $expected = [];
        $content = $this->getModuleClassTemplate();

        $routerNs = 'Router';
        $routerClass = 'RouterModule';
        $expected[$routerNs . '\\' . $routerClass] = $routerClass;
        $plugin1 = $this->createFileAndPackage(
            'vendor/pgframework/router/src/RouterModule.php',
            $content,
            'pgframework/router',
            $routerNs,
            $routerClass
        );

        $authNs = 'Auth\Auth';
        $authClass = 'AuthModule';
        $expected[$authNs . '\\' . $authClass] = $authClass;
        $plugin2 = $this->createFileAndPackage(
            'vendor/pgframework/auth/src/Auth/AuthModule.php',
            $content,
            'pgframework/auth',
            $authNs,
            $authClass
        );
        $this->assertFileExists($this->path . '/vendor/pgframework/auth/src/config.php');

        $fakeNs = 'FakeModule';
        $fakeClass = 'FakeModule';
        $expected[$fakeNs . '\\' . $fakeClass] = $fakeClass;
        $plugin3 = $this->createFileAndPackage(
            'vendor/pgframework/fake-module/src/FakeModule.php',
            $content,
            'pgframework/fake-module',
            $fakeNs,
            $fakeClass
        );

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

    /**
     * @param string $path Relative path
     * @param string $content Module class template
     * @param string $name Package name
     * @param string $namespace Module class Namespace
     * @param string $class Class name
     * @return Package
     */
    protected function createFileAndPackage(
        string $path,
        string $content,
        string $name,
        string $namespace,
        string $class
    ): Package {
        $this->createPhpFile(
            $path,
            sprintf($content, $namespace, $class)
        );
        $this->assertFileExists($this->path . '/' . $path);
        return $this->createPackage($name, $namespace);
    }

    public function testFindModuleClassSkipPsr0()
    {
        $path = $this->path . '/vendor/pgframework/fake-module';
        $plugin1 = new Package('pgframework/fake-module', '1.0', '1.0');
        $plugin1->setType('pg-module');
        $plugin1->setAutoload([
            'psr-0' => [
                'FakeModule' => 'src/',
            ],
        ]);

        $this->io->expects(self::never())->method('write');
        $modules = $this->plugin->findModuleClass($plugin1, $path);
        $this->assertSame([], $modules);
    }

    public function testFindModuleClassSPsr4()
    {
        $content = $this->getModuleClassTemplate();

        $fakeNs = 'FakeModule';
        $fakeClass = 'FakeModule';
        $plugin1 = $this->createFileAndPackage(
            'vendor/pgframework/fake-module/src/FakeModule.php',
            $content,
            'pgframework/fake-module',
            $fakeNs,
            $fakeClass
        );
        $path = $this->path . '/vendor/' . $plugin1->getPrettyName() . $plugin1->getTargetDir();
        $messages = ['<info>      Found pg-module: FakeModule</info>'];
        $this->io
            ->expects(self::once())
            ->method('write')
            ->with(self::callback(self::getIoMessageCallback($messages)));
        $modules = $this->plugin->findModuleClass($plugin1, $path);
        $this->assertSame(['FakeModule\FakeModule' => 'FakeModule'], $modules);
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

        $expected = [
            'Auth\Auth\AuthModule' => 'AuthModule',
            'FakeModule\FakeModule' => 'FakeModule',
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
        $this->assertSame($expected, $modules);
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

        $messages = [
            "<info>Config file\n $configFile \n don't exist in this project, writing dummy file</info>",
            '<info>Write module RouterModule in config file</info>',
            '<info>Write module UserModule in config file</info>',
            '<info>Write module AuthModule in config file</info>',
            '<info>Write module FakeModule in config file</info>',
        ];
        $this->io
            ->expects(self::exactly(5))
            ->method('write')
            ->with(self::callback(self::getIoMessageCallback($messages)));
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
}
