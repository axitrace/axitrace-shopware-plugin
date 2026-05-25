<?php

declare(strict_types=1);

namespace AxitraceShopware6\ScheduledTask;

use AxitraceShopware6\Entity\AxitraceFailedEventCollection;
use AxitraceShopware6\HttpClient\IngestionApiClient;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(handles: AxitraceRetryFailedEventsTask::class)]
final class AxitraceRetryFailedEventsHandler
{
    public function __construct(
        private readonly EntityRepository $failedEventRepository,
        private readonly IngestionApiClient $ingestionClient,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(AxitraceRetryFailedEventsTask $task): void
    {
        $this->run();
    }

    public function run(): void
    {
        $context = Context::createDefaultContext();

        $criteria = (new Criteria())
            ->addFilter(new RangeFilter('attempts', ['lt' => 3]))
            ->addFilter(new MultiFilter(
                MultiFilter::CONNECTION_OR,
                [
                    new EqualsFilter('lastAttemptAt', null),
                    new RangeFilter('lastAttemptAt', [
                        'lt' => (new \DateTimeImmutable('-5 minutes'))->format('Y-m-d H:i:s'),
                    ]),
                ]
            ))
            ->setLimit(100);

        /** @var AxitraceFailedEventCollection $rows */
        $rows = $this->failedEventRepository->search($criteria, $context)->getEntities();

        foreach ($rows as $row) {
            try {
                $payload = json_decode($row->getPayload(), true, 16, JSON_THROW_ON_ERROR);
                $this->ingestionClient->sendEvent($payload);
                // Success — delete the row from the retry queue
                $this->failedEventRepository->delete([['id' => $row->getId()]], $context);
            } catch (\Throwable $e) {
                $this->failedEventRepository->update([[
                    'id'             => $row->getId(),
                    'attempts'       => $row->getAttempts() + 1,
                    'lastAttemptAt'  => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                    'lastError'      => substr($e->getMessage(), 0, 500),
                ]], $context);

                $this->logger->critical(
                    'AxiTrace retry handler: event ' . $row->getEventId()
                    . ' failed (attempt ' . ($row->getAttempts() + 1) . '): ' . $e::class,
                );
            }
        }
    }
}
