<?php

declare(strict_types=1);

namespace AxitraceShopware6\Entity;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\DateTimeField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\LongTextField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

final class AxitraceFailedEventDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'axitrace_failed_event_log';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getCollectionClass(): string
    {
        return AxitraceFailedEventCollection::class;
    }

    public function getEntityClass(): string
    {
        return AxitraceFailedEventEntity::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required()),
            (new StringField('event_id', 'eventId'))->addFlags(new Required()),
            (new LongTextField('payload', 'payload'))->addFlags(new Required()),
            (new IntField('attempts', 'attempts'))->addFlags(new Required()),
            (new DateTimeField('created_at', 'createdAt'))->addFlags(new Required()),
            new DateTimeField('last_attempt_at', 'lastAttemptAt'),
            new LongTextField('last_error', 'lastError'),
        ]);
    }
}
