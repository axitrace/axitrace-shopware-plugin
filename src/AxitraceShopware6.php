<?php

declare(strict_types=1);

namespace AxitraceShopware6;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;

/**
 * Plugin bootstrap class for AxiTrace server-side tracking.
 *
 * Responsible only for lifecycle hooks (install / uninstall).
 * Business logic lives in dedicated services and subscribers.
 */
final class AxitraceShopware6 extends Plugin
{
    public function install(InstallContext $installContext): void
    {
        // Schema is managed via src/Resources/config/services.xml and
        // src/Migration/ migrations — nothing to do manually on install.
        parent::install($installContext);
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        if ($uninstallContext->keepUserData()) {
            return;
        }

        /** @var Connection $connection */
        $connection = $this->container->get(Connection::class);
        $connection->executeStatement('DROP TABLE IF EXISTS `axitrace_failed_event_log`');
    }
}
