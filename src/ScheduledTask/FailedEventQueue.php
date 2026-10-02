<?php

declare(strict_types=1);

namespace AxitraceShopware6\ScheduledTask;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Writes an undeliverable request to `axitrace_failed_event_log` so the
 * scheduled task ({@see AxitraceRetryFailedEventsHandler}) can resend it.
 *
 * The stored JSON is the request body plus one private envelope key
 * ({@see self::ENVELOPE_KEY}) that records which endpoint the body belongs to
 * and which Sales Channel sent it. The retry handler removes the envelope
 * before sending and uses the Sales Channel to read the secret key again, so
 * the key itself is never written to the database. Rows written before 0.5.0
 * have no envelope and are resent as pixel events without a key, exactly as
 * they always were.
 */
final class FailedEventQueue
{
    public const ENVELOPE_KEY = '_axitrace_retry';
    public const ENDPOINT_PIXEL = 'pixel';
    public const ENDPOINT_REFUND = 'refund';

    public function __construct(
        private readonly EntityRepository $failedEventRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Never throws: a failure to persist is logged at critical, except a
     * duplicate row (the same event already queued), which is expected.
     *
     * @param array<string, mixed> $payload
     */
    public function enqueue(
        string $eventId,
        array $payload,
        string $endpoint,
        string $salesChannelId,
        \Throwable $error,
        Context $context,
    ): void {
        $payload[self::ENVELOPE_KEY] = [
            'endpoint'       => $endpoint,
            'salesChannelId' => $salesChannelId,
        ];

        try {
            $this->failedEventRepository->create([[
                'id'            => Uuid::randomHex(),
                'eventId'       => $eventId,
                'payload'       => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'attempts'      => 0,
                'createdAt'     => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'lastAttemptAt' => null,
                'lastError'     => substr($error->getMessage(), 0, 500),
            ]], $context);
        } catch (\Throwable $persistError) {
            if (stripos($persistError->getMessage(), 'duplicate') === false
                && stripos($persistError->getMessage(), 'unique') === false
            ) {
                $this->logger->critical('AxiTrace: failed to persist failed-event row for ' . $eventId . ': ' . $persistError::class);
            }
        }
    }

    /**
     * Splits a stored row back into the request body and its envelope.
     *
     * @param array<string, mixed> $stored
     *
     * @return array{payload: array<string, mixed>, endpoint: string, salesChannelId: string|null}
     */
    public static function unwrap(array $stored): array
    {
        $envelope = $stored[self::ENVELOPE_KEY] ?? null;
        unset($stored[self::ENVELOPE_KEY]);

        $endpoint = is_array($envelope) && ($envelope['endpoint'] ?? null) === self::ENDPOINT_REFUND
            ? self::ENDPOINT_REFUND
            : self::ENDPOINT_PIXEL;
        $salesChannelId = is_array($envelope) && is_string($envelope['salesChannelId'] ?? null) && $envelope['salesChannelId'] !== ''
            ? $envelope['salesChannelId']
            : null;

        return ['payload' => $stored, 'endpoint' => $endpoint, 'salesChannelId' => $salesChannelId];
    }
}
