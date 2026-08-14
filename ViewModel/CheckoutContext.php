<?php

declare(strict_types=1);

namespace AxiTrace\Tracking\ViewModel;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * Exposes the active quote's cart value/currency/item count on the checkout
 * page (checkout_index_index) so pixel.phtml can fire `begin_checkout` and
 * `add_payment_info` with a real cart total instead of an empty payload.
 *
 * Reads Magento\Checkout\Model\Session::getQuote() — the same server-authoritative
 * quote the checkout SPA itself renders totals from — rather than scraping the
 * Knockout-rendered DOM, which is theme- and step-dependent.
 *
 * Wrapped defensively like OrderConfirmationContext: any failure returns an
 * empty payload so a broken/absent quote never interrupts the checkout render.
 */
class CheckoutContext implements ArgumentInterface
{
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getCheckoutPayload(): array
    {
        try {
            $quote = $this->checkoutSession->getQuote();
            if ($quote === null || !$quote->getId()) {
                return [];
            }

            $itemCount = 0;
            foreach ($quote->getAllVisibleItems() as $item) {
                $itemCount += (int) round((float) $item->getQty());
            }

            return [
                'value'     => (float) $quote->getGrandTotal(),
                'currency'  => (string) $quote->getQuoteCurrencyCode(),
                'itemCount' => $itemCount,
            ];
        } catch (\Throwable) {
            return [];
        }
    }
}
