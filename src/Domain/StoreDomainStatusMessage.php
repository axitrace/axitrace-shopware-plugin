<?php

declare(strict_types=1);

namespace AxitraceShopware6\Domain;

/**
 * Tells the merchant, in plain words, whether AxiTrace accepts the browser events
 * of each storefront domain. Shown in the read-only "Store domain verification"
 * field of the plugin settings.
 *
 * Why: when a domain is not verified AxiTrace drops every page view, add-to-cart
 * and checkout of that storefront, while purchases still arrive from the server,
 * so nothing else in the shop looks broken.
 */
final class StoreDomainStatusMessage
{
    /**
     * @param array{checked_at: string, hosts: list<array{host: string, status: string, reason: string|null, browser_events_rejected: bool}>, error: string|null} $status
     */
    public static function describe(array $status): string
    {
        $lines = [];
        foreach ($status['hosts'] as $host) {
            $problem = self::problem($host);
            $lines[] = $problem === null
                ? sprintf('OK: %s is verified, AxiTrace accepts the browser events of this storefront.', $host['host'])
                : 'Problem: ' . $problem;
        }

        if ($lines === []) {
            $lines[] = match ($status['error']) {
                null, 'no_public_key' => 'No storefront domain to verify yet. Enter the public key (globally or for a Sales Channel with a storefront domain) and save.',
                default => self::errorLine($status['error']),
            };
        } elseif ($status['error'] !== null) {
            $lines[] = 'The last check did not complete: ' . self::errorLine($status['error']);
        }

        if ($status['checked_at'] !== '') {
            $lines[] = sprintf('Last checked: %s. Checked again every day and every time you save these settings.', $status['checked_at']);
        }

        return implode("\n", $lines);
    }

    /**
     * What is wrong with one host in one sentence a merchant understands, or
     * null when nothing is.
     *
     * @param array{host: string, status: string, reason: string|null, browser_events_rejected: bool} $host
     */
    public static function problem(array $host): ?string
    {
        $name = $host['host'];
        if ($host['status'] === 'verified') {
            return null;
        }
        if ($host['status'] === 'pending') {
            return sprintf('AxiTrace is still verifying %s. It retries automatically within 15 minutes.', $name);
        }

        return match ((string) $host['reason']) {
            'staging_host', 'not_public_host', 'invalid_host' => sprintf(
                '%s looks like a test or local copy of your shop, so AxiTrace does not accept its browser events. Your live storefront is verified automatically.',
                $name,
            ),
            'held_by_other_workspace' => sprintf(
                '%s is connected to a different AxiTrace workspace. Use the public key of that workspace here, or write to support@axitrace.com.',
                $name,
            ),
            'proof_other_workspace' => sprintf(
                '%s answered AxiTrace with a different public key. Make sure the Sales Channel of this domain uses the public key of your AxiTrace workspace.',
                $name,
            ),
            'proof_signature_missing', 'proof_signature_invalid' => sprintf(
                'The secret key of the Sales Channel of %s does not belong to the same AxiTrace workspace as its public key. Copy both keys from the same workspace.',
                $name,
            ),
            default => sprintf(
                'AxiTrace could not open the verification address of %1$s (a firewall, security extension or a missing root domain for the Sales Channel may block it). Allow requests to https://%1$s/axitrace/domain-proof, or add the domain in AxiTrace under Settings > Tracking & domains.',
                $name,
            ),
        };
    }

    private static function errorLine(string $error): string
    {
        return match ($error) {
            'invalid_secret_key' => 'AxiTrace rejected the secret key. Copy the secret key from the same AxiTrace workspace as the public key, or leave it empty.',
            'workspace_not_found', 'invalid_key_format' => 'AxiTrace does not know this public key. Copy it again from your AxiTrace workspace.',
            'rate_limited' => 'Too many verification requests in the last hour. AxiTrace is asked again automatically tomorrow, or save these settings later.',
            default => 'AxiTrace could not be reached to verify your storefront domains. It tries again automatically every day, or save these settings to try now.',
        };
    }
}
