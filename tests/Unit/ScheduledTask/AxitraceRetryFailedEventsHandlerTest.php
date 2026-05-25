<?php

declare(strict_types=1);

namespace AxitraceShopware6\Tests\Unit\ScheduledTask;

use AxitraceShopware6\Entity\AxitraceFailedEventCollection;
use AxitraceShopware6\Entity\AxitraceFailedEventEntity;
use AxitraceShopware6\Exception\IngestionUnreachableException;
use AxitraceShopware6\HttpClient\IngestionApiClient;
use AxitraceShopware6\ScheduledTask\AxitraceRetryFailedEventsHandler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\AggregationResultCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;

final class AxitraceRetryFailedEventsHandlerTest extends TestCase
{
    private EntityRepository&MockObject $failedEventRepository;
    private IngestionApiClient&MockObject $ingestionClient;
    private LoggerInterface&MockObject $logger;
    private AxitraceRetryFailedEventsHandler $handler;

    protected function setUp(): void
    {
        $this->failedEventRepository = $this->createMock(EntityRepository::class);
        $this->ingestionClient       = $this->createMock(IngestionApiClient::class);
        $this->logger                = $this->createMock(LoggerInterface::class);

        $this->handler = new AxitraceRetryFailedEventsHandler(
            $this->failedEventRepository,
            $this->ingestionClient,
            $this->logger,
        );
    }

    // -------------------------------------------------------------------------
    // Test 1: Successful retry deletes the row
    // -------------------------------------------------------------------------

    public function testSuccessfulRetryDeletesRow(): void
    {
        $entity = $this->buildEntity('event-001', '{"event":"Purchase"}', 1);

        $this->mockRepositorySearch($entity);

        $this->ingestionClient
            ->expects(self::once())
            ->method('sendEvent')
            ->with(['event' => 'Purchase']);

        $this->failedEventRepository
            ->expects(self::once())
            ->method('delete')
            ->with([['id' => $entity->getId()]]);

        $this->failedEventRepository
            ->expects(self::never())
            ->method('update');

        $this->handler->run();
    }

    // -------------------------------------------------------------------------
    // Test 2: Failed retry increments attempts and updates lastAttemptAt
    // -------------------------------------------------------------------------

    public function testFailedRetryIncrementsAttempts(): void
    {
        $entity = $this->buildEntity('event-002', '{"event":"Purchase"}', 1);

        $this->mockRepositorySearch($entity);

        $this->ingestionClient
            ->expects(self::once())
            ->method('sendEvent')
            ->willThrowException(new IngestionUnreachableException('timeout'));

        $this->failedEventRepository
            ->expects(self::never())
            ->method('delete');

        $this->failedEventRepository
            ->expects(self::once())
            ->method('update')
            ->with(self::callback(static function (array $updates): bool {
                $update = $updates[0];
                return $update['id'] !== ''
                    && $update['attempts'] === 2
                    && isset($update['lastAttemptAt'])
                    && isset($update['lastError']);
            }));

        $this->logger
            ->expects(self::once())
            ->method('critical')
            ->with(self::stringContains('event-002'));

        $this->handler->run();
    }

    // -------------------------------------------------------------------------
    // Test 3: Criteria filter excludes rows with attempts >= 3
    // -------------------------------------------------------------------------

    public function testThreeAttemptsRowSkipped(): void
    {
        // A search result with no rows simulates the filter excluding attempts >= 3.
        // We verify the criteria passed to search() contains the lt:3 range filter.
        $capturedCriteria = null;

        $emptyCollection = new AxitraceFailedEventCollection([]);

        $this->failedEventRepository
            ->expects(self::once())
            ->method('search')
            ->with(
                self::callback(static function (Criteria $criteria) use (&$capturedCriteria): bool {
                    $capturedCriteria = $criteria;
                    return true;
                }),
                self::isInstanceOf(Context::class),
            )
            ->willReturn($this->buildSearchResult($emptyCollection));

        $this->ingestionClient->expects(self::never())->method('sendEvent');
        $this->failedEventRepository->expects(self::never())->method('delete');
        $this->failedEventRepository->expects(self::never())->method('update');

        $this->handler->run();

        // Confirm the criteria includes a RangeFilter limiting attempts < 3
        self::assertNotNull($capturedCriteria);
        $filtersJson = json_encode($capturedCriteria->getFilters(), JSON_THROW_ON_ERROR);
        self::assertStringContainsString('attempts', $filtersJson);
        self::assertStringContainsString('lt', $filtersJson);
        self::assertStringContainsString('3', $filtersJson);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildEntity(string $eventId, string $payload, int $attempts): AxitraceFailedEventEntity
    {
        $entity = new AxitraceFailedEventEntity();
        $entity->setId(\Shopware\Core\Framework\Uuid\Uuid::randomHex());
        $entity->setEventId($eventId);
        $entity->setPayload($payload);
        $entity->setAttempts($attempts);
        $entity->setCreatedAt(new \DateTimeImmutable());

        return $entity;
    }

    private function mockRepositorySearch(AxitraceFailedEventEntity $entity): void
    {
        $collection = new AxitraceFailedEventCollection([$entity]);
        $result     = $this->buildSearchResult($collection);

        $this->failedEventRepository
            ->expects(self::once())
            ->method('search')
            ->willReturn($result);
    }

    private function buildSearchResult(AxitraceFailedEventCollection $collection): EntitySearchResult
    {
        return new EntitySearchResult(
            'axitrace_failed_event_log',
            $collection->count(),
            $collection,
            new AggregationResultCollection(),
            new Criteria(),
            Context::createDefaultContext(),
        );
    }
}
