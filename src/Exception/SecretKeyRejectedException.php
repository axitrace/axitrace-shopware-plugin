<?php

declare(strict_types=1);

namespace AxitraceShopware6\Exception;

/**
 * AxiTrace answered 401 to a request that carried the configured secret key:
 * the key is wrong, revoked, or belongs to another workspace. Retrying the
 * same request with the same key can never succeed, so this is deliberately
 * NOT an {@see IngestionUnreachableException} and never enters the retry queue.
 */
final class SecretKeyRejectedException extends \RuntimeException
{
}
