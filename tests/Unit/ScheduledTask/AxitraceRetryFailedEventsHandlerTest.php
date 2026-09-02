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
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AxitraceRetryFailedEventsHandlerTest extends TestCase
{
    private EntityRepository&MockObject $failedEventRepository;
    private IngestionApiClient $ingestionClient;
    private LoggerInterface&MockObject $logger;
    private AxitraceRetryFailedEventsHandler $handler;

    /** Number of HTTP requests the mocked transport actually served. */
    private int $requestCount = 0;

    protected function setUp(): void
    {
        $this->failedEventRepository = $this->createMock(EntityRepository::class);
        $this->logger                = $this->createMock(LoggerInterface::class);
        $this->requestCount          = 0;
    }

    /**
     * IngestionApiClient is final and cannot be doubled — build the real client
     * on a MockHttpClient configured per test instead.
     */
    private function makeClient(MockHttpClient $http): void
    {
        $this->ingestionClient = new IngestionApiClient($http, $this->logger, null);

        $this->handler = new AxitraceRetryFailedEventsHandler(
            $this->failedEventRepository,
            $this->ingestionClient,
            $this->logger,
        );
    }

    private function makeSuccessClient(): void
    {
        $this->makeClient(new MockHttpClient(
            function (string $method, string $url, array $options): MockResponse {
                $this->requestCount++;

                return new MockResponse('', ['http_code' => 202]);
            },
        ));
    }

    private function makeUnreachableClient(): void
    {
        $this->makeClient(new MockHttpClient(
            function (string $method, string $url, array $options): never {
                $this->requestCount++;

                throw new TransportException('timeout');
            },
        ));
    }

    // -------------------------------------------------------------------------
    // Test 1: Successful retry deletes the row
    // -------------------------------------------------------------------------

    public function testSuccessfulRetryDeletesRow(): void
    {
        $this->makeSuccessClient();

        $entity = $this->buildEntity('event-001', '{"event":"Purchase"}', 1);

        $this->mockRepositorySearch($entity);

        $this->failedEventRepository
            ->expects(self::once())
            ->method('delete')
            ->with([['id' => $entity->getId()]]);

        $this->failedEventRepository
            ->expects(self::never())
            ->method('update');

        $this->handler->run();

        self::assertSame(1, $this->requestCount, 'Exactly one ingestion request must have been sent');
    }

    // -------------------------------------------------------------------------
    // Test 2: Failed retry increments attempts and updates lastAttemptAt
    // -------------------------------------------------------------------------

    public function testFailedRetryIncrementsAttempts(): void
    {
        $this->makeUnreachableClient();

        $entity = $this->buildEntity('event-002', '{"event":"Purchase"}', 1);

        $this->mockRepositorySearch($entity);

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

        // Two critical entries: one from the real IngestionApiClient (transport
        // failure), one from the retry handler naming the event.
        $this->logger
            ->expects(self::exactly(2))
            ->method('critical')
            ->with(self::callback(static function (string $message): bool {
                return str_contains($message, 'ingestion-api transport failure')
                    || str_contains($message, 'event-002');
            }));

        $this->handler->run();

        self::assertSame(1, $this->requestCount);
    }

    // -------------------------------------------------------------------------
    // Test 3: Criteria filter excludes rows with attempts >= 3
    // -------------------------------------------------------------------------

    public function testThreeAttemptsRowSkipped(): void
    {
        $this->makeSuccessClient();

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

        $this->failedEventRepository->expects(self::never())->method('delete');
        $this->failedEventRepository->expects(self::never())->method('update');

        $this->handler->run();

        // No row survived the filter → no ingestion request may have been made.
        self::assertSame(0, $this->requestCount);

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
