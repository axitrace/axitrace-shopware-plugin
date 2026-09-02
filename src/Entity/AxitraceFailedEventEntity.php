<?php

declare(strict_types=1);

namespace AxitraceShopware6\Entity;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

final class AxitraceFailedEventEntity extends Entity
{
    use EntityIdTrait;

    public string $eventId = '';

    public string $payload = '';

    public int $attempts = 0;

    // Must match the parent Entity property type exactly (?\DateTimeInterface) —
    // PHP property types are invariant, and a narrower type here fatals depending
    // on class-load order.
    public ?\DateTimeInterface $createdAt = null;

    public ?\DateTimeInterface $lastAttemptAt = null;

    public ?string $lastError = null;

    public function getEventId(): string
    {
        return $this->eventId;
    }

    public function setEventId(string $eventId): void
    {
        $this->eventId = $eventId;
    }

    public function getPayload(): string
    {
        return $this->payload;
    }

    public function setPayload(string $payload): void
    {
        $this->payload = $payload;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function setAttempts(int $attempts): void
    {
        $this->attempts = $attempts;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): void
    {
        $this->createdAt = $createdAt;
    }

    public function getLastAttemptAt(): ?\DateTimeInterface
    {
        return $this->lastAttemptAt;
    }

    public function setLastAttemptAt(?\DateTimeInterface $lastAttemptAt): void
    {
        $this->lastAttemptAt = $lastAttemptAt;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function setLastError(?string $lastError): void
    {
        $this->lastError = $lastError;
    }
}
