<?php

declare(strict_types=1);

namespace AxitraceShopware6\Entity;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<AxitraceFailedEventEntity>
 */
final class AxitraceFailedEventCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return AxitraceFailedEventEntity::class;
    }
}
