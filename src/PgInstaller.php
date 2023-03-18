<?php

declare(strict_types=1);

namespace PgFramework\Composer;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;

class PgInstaller implements PluginInterface, EventSubscriberInterface
{
    /**
     * @inheritDoc
     */
    public function activate(Composer $composer, IOInterface $io)
    {
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
            'post-autoload-dump' => 'postAutoloadDump',
        ];
    }

    /**
     * Called whenever composer (re)generates the autoloader.
     *
     * Recreates PgFramework Module path map, based on composer information
     * and available app plugins.
     *
     * @param Event $event Composer's event object.
     * @return void
     */
    public function postAutoloadDump(Event $event): void
    {
        $composer = $event->getComposer();
        $config = $composer->getConfig();

        $vendorDir = realpath($config->get('vendor-dir'));

        $packages = $composer->getRepositoryManager()->getLocalRepository()->getPackages();
        $extra = $event->getComposer()->getPackage()->getExtra();
    }
}