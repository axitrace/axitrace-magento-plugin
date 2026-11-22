<?php

declare(strict_types=1);

namespace AxiTrace\Tracking\Model\Domain;

/**
 * Turns the stored claim outcome into plain sentences for the merchant: one line
 * per storefront host (verified, pending or what is wrong), plus one line when
 * the claim itself failed.
 *
 * Why: when a domain is not verified AxiTrace drops every page view, add-to-cart
 * and checkout of that store view while purchases still arrive from the server,
 * so nothing else in the store looks broken.
 */
class DomainStatusMessages
{
    public const LEVEL_SUCCESS = 'success';
    public const LEVEL_WARNING = 'warning';
    public const LEVEL_ERROR   = 'error';

    /**
     * @param array{checked_at: int, hosts: list<array{host: string, status: string, reason: string|null, browser_events_rejected: bool}>, error: string|null}|null $status
     * @return list<array{level: string, text: string}>
     */
    public function lines(?array $status): array
    {
        if ($status === null) {
            return [$this->line(
                self::LEVEL_WARNING,
                __('Your store domain is verified automatically after you save this configuration, or use the button below.')
            )];
        }

        $lines = [];
        foreach ($status['hosts'] as $host) {
            $lines[] = $this->hostLine($host);
        }

        $error = $status['error'];
        if ($error === 'no_public_key') {
            if ($lines === []) {
                $lines[] = $this->line(
                    self::LEVEL_WARNING,
                    __('Enable tracking and save a workspace public key; your store domain is then verified automatically.')
                );
            }
        } elseif ($error !== null) {
            $lines[] = $this->line(self::LEVEL_ERROR, $this->claimError($error));
        }

        if ($lines === []) {
            $lines[] = $this->line(
                self::LEVEL_WARNING,
                __('AxiTrace returned no store domain to verify. Check the base URLs of your store views.')
            );
        }

        return $lines;
    }

    /**
     * @param array{host: string, status: string, reason: string|null, browser_events_rejected: bool} $host
     * @return array{level: string, text: string}
     */
    private function hostLine(array $host): array
    {
        $name = $host['host'];
        if ($host['status'] === 'verified') {
            return $this->line(
                self::LEVEL_SUCCESS,
                __('Store domain %1 is verified: AxiTrace accepts the browser events of your store.', $name)
            );
        }
        if ($host['status'] === 'pending') {
            return $this->line(
                self::LEVEL_WARNING,
                __('AxiTrace is still verifying %1. It retries automatically within 15 minutes.', $name)
            );
        }

        return $this->line(self::LEVEL_ERROR, $this->hostProblem($name, (string) $host['reason']));
    }

    private function hostProblem(string $name, string $reason): string
    {
        switch ($reason) {
            case 'staging_host':
            case 'not_public_host':
            case 'invalid_host':
                return (string) __(
                    '%1 looks like a test or local copy of your store, so AxiTrace does not accept its browser events. Your live store is verified automatically.',
                    $name
                );
            case 'held_by_other_workspace':
                return (string) __(
                    '%1 is connected to a different AxiTrace workspace. Use the public key of that workspace here, or write to support@axitrace.com.',
                    $name
                );
            case 'proof_other_workspace':
                return (string) __(
                    '%1 answered AxiTrace with a different public key. Make sure this store view uses the public key of your AxiTrace workspace.',
                    $name
                );
            case 'proof_signature_missing':
            case 'proof_signature_invalid':
                return (string) __(
                    'The secret key of %1 does not belong to the same AxiTrace workspace as the public key. Copy both keys from the same workspace.',
                    $name
                );
            default:
                return (string) __(
                    'AxiTrace could not open the verification address of %1 (a firewall, security extension or maintenance mode may block it). Allow requests to https://%1/axitrace/domain/proof, or add the domain in AxiTrace under Settings > Tracking & domains.',
                    $name
                );
        }
    }

    private function claimError(string $error): string
    {
        switch ($error) {
            case 'invalid_secret_key':
                return (string) __(
                    'AxiTrace did not accept the secret key. Copy the public key and the secret key from the same AxiTrace workspace, or clear the secret key.'
                );
            case 'workspace_not_found':
            case 'invalid_key_format':
                return (string) __(
                    'AxiTrace has no workspace with this public key. Copy the public key from your AxiTrace workspace.'
                );
            case 'rate_limited':
                return (string) __(
                    'Too many verification attempts in the last hour. AxiTrace tries again automatically every day, or use the button below later.'
                );
            case 'invalid_hosts':
                return (string) __(
                    'AxiTrace could not use the base URLs of your store views as store domains. Check Stores > Configuration > General > Web.'
                );
            default:
                return (string) __(
                    'AxiTrace could not be reached to verify your store domain. It tries again automatically every day, or use the button below.'
                );
        }
    }

    /**
     * @return array{level: string, text: string}
     */
    private function line(string $level, \Stringable|string $text): array
    {
        return ['level' => $level, 'text' => (string) $text];
    }
}
