<?php

declare(strict_types=1);

namespace AxiTrace\Tracking\Observer;

use AxiTrace\Tracking\Model\Domain\StoreDomainClaimer;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * Claims the storefront hosts after the AxiTrace configuration section is saved
 * (`admin_system_config_changed_section_axitrace`), so a new or changed public
 * key verifies the store domain without any further step. The claimer never
 * throws; its outcome is shown on the configuration page that the save returns to.
 */
class ClaimStoreDomainObserver implements ObserverInterface
{
    public function __construct(private readonly StoreDomainClaimer $claimer)
    {
    }

    public function execute(Observer $observer): void
    {
        $this->claimer->claim();
    }
}
