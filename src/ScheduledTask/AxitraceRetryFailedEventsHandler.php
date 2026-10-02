<?php

declare(strict_types=1);

namespace AxitraceShopware6\ScheduledTask;

use AxitraceShopware6\Config\PluginConfig;
use AxitraceShopware6\Entity\AxitraceFailedEventCollection;
use AxitraceShopware6\Exception\SecretKeyRejectedException;
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
        // Optional so rows can still be resent when the handler is built without
        // config (tests, pre-0.5.0 wiring): such a resend carries no secret key.
        private readonly ?PluginConfig $config = null,
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
                $stored = json_decode($row->getPayload(), true, 16, JSON_THROW_ON_ERROR);
                $this->resend(is_array($stored) ? $stored : []);
                // Success — delete the row from the retry queue
                $this->failedEventRepository->delete([['id' => $row->getId()]], $context);
            } catch (SecretKeyRejectedException) {
                // A refund whose key AxiTrace rejects can never be delivered with it
                // (the client already logged the rejection at critical). It is not
                // sent without a valid key, so the row is dropped rather than retried.
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

    /**
     * Resends one stored request to the endpoint it was meant for. The secret
     * key is read again from the Sales Channel's config (it is never stored in
     * the queue). A purchase resent without a key drops its cost fields, the
     * same rule the subscriber applies on the first attempt, and a purchase
     * whose key is rejected falls back to a keyless send inside the client; a
     * refund without a key cannot be sent and throws, which counts as a failed
     * attempt, and a refund whose key is rejected is dropped.
     *
     * @param array<string, mixed> $stored
     */
    private function resend(array $stored): void
    {
        ['payload' => $payload, 'endpoint' => $endpoint, 'salesChannelId' => $salesChannelId] = FailedEventQueue::unwrap($stored);

        $secretKey = $salesChannelId !== null && $this->config !== null
            ? $this->config->getSecretKey($salesChannelId)
            : '';

        if ($endpoint === FailedEventQueue::ENDPOINT_REFUND) {
            $this->ingestionClient->sendRefund($payload, $secretKey);

            return;
        }

        if ($secretKey === '') {
            $payload = IngestionApiClient::withoutUnitCosts($payload);
        }

        $this->ingestionClient->sendEvent($payload, $secretKey);
    }
}
