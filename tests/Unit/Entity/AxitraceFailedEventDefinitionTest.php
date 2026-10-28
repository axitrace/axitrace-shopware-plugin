<?php

declare(strict_types=1);

namespace AxitraceShopware6\Tests\Unit\Entity;

use AxitraceShopware6\Entity\AxitraceFailedEventDefinition;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\DataAbstractionLayer\DefinitionInstanceRegistry;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StorageAware;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Every column the DAL reads must exist after the plugin's migrations ran.
 * Shopware adds `createdAt` and `updatedAt` to every entity definition, so a
 * table without `updated_at` makes every repository search fail with
 * "Unknown column", which kept the retry queue from ever resending an event.
 */
final class AxitraceFailedEventDefinitionTest extends TestCase
{
    public function testEveryStorageFieldHasAColumnAfterAllMigrations(): void
    {
        $definition = new AxitraceFailedEventDefinition();
        $definition->compile($this->createMock(DefinitionInstanceRegistry::class));

        $columns = $this->columnsCreatedByMigrations();

        foreach ($definition->getFields() as $field) {
            if (!$field instanceof StorageAware) {
                continue;
            }
            self::assertContains(
                $field->getStorageName(),
                $columns,
                sprintf('The DAL reads %s.%s, but no migration creates that column.', AxitraceFailedEventDefinition::ENTITY_NAME, $field->getStorageName()),
            );
        }
    }

    public function testAddingUpdatedAtIsSafeWhenTheColumnAlreadyExists(): void
    {
        $statements = [];
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn('updated_at');
        $connection->method('executeStatement')->willReturnCallback(static function (string $sql) use (&$statements): int {
            $statements[] = $sql;

            return 0;
        });

        foreach ($this->migrations() as $migration) {
            $migration->update($connection);
        }

        foreach ($statements as $sql) {
            self::assertStringNotContainsStringIgnoringCase('ADD COLUMN', $sql, 'A reinstall that kept user data must not add the column twice.');
        }
    }

    /**
     * @return list<string> column names from CREATE TABLE and ALTER TABLE ... ADD COLUMN
     */
    private function columnsCreatedByMigrations(): array
    {
        $statements = [];
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn(false);
        $connection->method('executeStatement')->willReturnCallback(static function (string $sql) use (&$statements): int {
            $statements[] = $sql;

            return 0;
        });

        foreach ($this->migrations() as $migration) {
            $migration->update($connection);
        }

        $columns = [];
        foreach ($statements as $sql) {
            preg_match_all('/^\s*`([a-z_]+)`\s+[A-Z]/m', $sql, $created);
            preg_match_all('/ADD COLUMN\s+`([a-z_]+)`/i', $sql, $added);
            $columns = [...$columns, ...$created[1], ...$added[1]];
        }

        return array_values(array_unique($columns));
    }

    /**
     * @return list<MigrationStep> the plugin's migrations, oldest first
     */
    private function migrations(): array
    {
        $files = glob(__DIR__ . '/../../../src/Migration/Migration*.php') ?: [];
        sort($files);

        $migrations = [];
        foreach ($files as $file) {
            $class = 'AxitraceShopware6\\Migration\\' . basename($file, '.php');
            $migration = new $class();
            self::assertInstanceOf(MigrationStep::class, $migration);
            $migrations[] = $migration;
        }

        return $migrations;
    }
}
