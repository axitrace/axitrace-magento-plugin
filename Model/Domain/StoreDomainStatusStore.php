<?php

declare(strict_types=1);

namespace AxiTrace\Tracking\Model\Domain;

use Magento\Framework\FlagManager;

/**
 * Keeps the last answer of the store domain claim in Magento's flag table.
 *
 * The flag table (not core_config_data) holds it because it changes from cron and
 * must be read fresh, without a config cache flush.
 *
 * Shape: {checked_at: int, hosts: [{host, status, reason, browser_events_rejected}], error: string|null}
 */
class StoreDomainStatusStore
{
    public const FLAG_CODE = 'axitrace_store_domain_status';

    public function __construct(private readonly FlagManager $flagManager)
    {
    }

    /**
     * @return array{checked_at: int, hosts: list<array{host: string, status: string, reason: string|null, browser_events_rejected: bool}>, error: string|null}|null
     */
    public function load(): ?array
    {
        $value = $this->flagManager->getFlagData(self::FLAG_CODE);
        if (!is_array($value)) {
            return null;
        }

        return [
            'checked_at' => isset($value['checked_at']) ? (int) $value['checked_at'] : 0,
            'hosts'      => isset($value['hosts']) && is_array($value['hosts']) ? self::hostsOf($value['hosts']) : [],
            'error'      => isset($value['error']) && is_string($value['error']) ? $value['error'] : null,
        ];
    }

    /**
     * @param array{checked_at: int, hosts: list<array{host: string, status: string, reason: string|null, browser_events_rejected: bool}>, error: string|null} $status
     */
    public function save(array $status): void
    {
        $this->flagManager->saveFlag(self::FLAG_CODE, $status);
    }

    /**
     * The well-formed host entries of a decoded claim answer.
     *
     * @param array<mixed> $raw
     * @return list<array{host: string, status: string, reason: string|null, browser_events_rejected: bool}>
     */
    public static function hostsOf(array $raw): array
    {
        $hosts = [];
        foreach ($raw as $entry) {
            if (!is_array($entry) || !is_string($entry['host'] ?? null) || !is_string($entry['status'] ?? null)) {
                continue;
            }
            $hosts[] = [
                'host'                    => $entry['host'],
                'status'                  => $entry['status'],
                'reason'                  => is_string($entry['reason'] ?? null) ? $entry['reason'] : null,
                'browser_events_rejected' => !empty($entry['browser_events_rejected']),
            ];
        }

        return $hosts;
    }
}
