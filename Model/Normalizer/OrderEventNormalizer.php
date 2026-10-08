<?php

declare(strict_types=1);

namespace AxiTrace\Tracking\Model\Normalizer;

use AxiTrace\Tracking\Model\Consent\CookieRestrictionConsentResolver;
use AxiTrace\Tracking\Model\Identity\BrowserIdentity;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;

/**
 * Builds the GeneratedEvent-shaped payload that ingestion-api expects.
 *
 * Output shape mirrors WooCommerceEventNormalizer output in the velitrack repo:
 *   {
 *     event, eventSalt, transactionId, orderId, incrementId,
 *     workspace_public_key, source: "magento", timestamp, ip, userAgent,
 *     userId?, sessionId?,           // AxiTrace vt_vid / vt_sid, when captured
 *     pluginVersion, sdkVersion,
 *     billingCity, billingCountry, billingZip,
 *     data: {
 *       client: { email, phone },
 *       products: [{ productId, externalId, sku, name, quantity, price, currency,
 *                    unitCost?: { amount, currency } }],
 *       revenue: { amount, currency },
 *       value: <float>,
 *       tax: <float>, shipping: <float>, taxesIncluded: true,
 *       fbp?, fbc?, ttp?, rdt_uuid?, obref?, _ga?,             // browser ids
 *       gclid?, gbraid?, wbraid?, ttclid?, rdt_cid?, oppref?,  // click ids
 *       consent?: "granted"|"denied" // present only when the store asks for consent
 *     }
 *   }
 *
 * Identity: `userId` / `sessionId` carry the AxiTrace visitor and session cookies
 * (vt_vid / vt_sid) captured when the order was placed; ingestion stores the purchase
 * under that visitor, which is what links it to the visitor profile and its ad clicks
 * (the same contract as the WooCommerce and Shopware plugins and the PHP SDK). `ip`
 * and `userAgent` are the shopper's own; with no captured User-Agent the field stays
 * empty and ingestion falls back to the sending request's, as before 0.4.0. Every
 * browser and click id travels as a bare value under `data`, named exactly as the
 * event worker reads it. Each key is present only when captured.
 *
 * PII is forwarded in plain text per project memory - the Facebook CAPI PHP SDK
 * and TikTok Events API auto-hash; only `external_id` requires manual SHA-256.
 *
 * Currency: read from $order->getOrderCurrencyCode() (presentation currency),
 * not base currency - matches AstrophotoMarket lesson logged in project memory.
 *
 * Profit fields: `tax` (order tax amount) and `shipping` (shipping charged including
 * its tax) are plain numbers in the order currency, like `revenue`. `taxesIncluded`
 * is always true because the revenue sent is Magento's grand total, which always
 * contains the tax. `unitCost` is cost data: it is written only when the caller says
 * the request is authenticated with the workspace secret key (ingestion strips cost
 * fields from anything else), and only when the store's base currency (the currency
 * of Magento product costs) equals the order currency, because AxiTrace rejects a
 * cost in another currency than the revenue.
 */
class OrderEventNormalizer
{
    private const PLUGIN_VERSION = '0.4.1';
    private const SDK_VERSION    = 'magento-1.0';
    private const SOURCE         = 'magento';

    public function __construct(
        private readonly OrderLineCostResolver $lineCostResolver,
    ) {
    }

    /**
     * @param BrowserIdentity|null $browser The shopper's browser identity captured when the
     *                                      order was placed. Null when there is none.
     * @param string|null $consent The visitor's Cookie Restriction Mode decision
     *                             ('granted' / 'denied'), captured by
     *                             OrderStateTransitionObserver. Null when this purchase
     *                             states nothing about consent.
     * @param bool $includeCosts True only when the request will carry the workspace
     *                           secret key; adds `unitCost` to the lines that have one.
     * @return array<string, mixed>
     */
    public function normalize(
        OrderInterface $order,
        string $eventIdHash,
        string $workspacePublicKey,
        ?BrowserIdentity $browser = null,
        ?string $consent = null,
        bool $includeCosts = false,
    ): array {
        $billing = $order->getBillingAddress();
        $orderCurrency = (string) $order->getOrderCurrencyCode();
        $costsAllowed = $includeCosts && $this->costCurrencyMatches($order, $orderCurrency);

        $products = [];
        foreach ($order->getAllVisibleItems() as $item) {
            if ($item instanceof OrderItemInterface) {
                $line = [
                    'productId' => (string) $item->getProductId(),
                    'sku'       => (string) $item->getSku(),
                    'name'      => (string) $item->getName(),
                    'quantity'  => (float) $item->getQtyOrdered(),
                    'price'     => (float) $item->getPrice(),
                    'currency'  => $orderCurrency,
                ];

                $externalId = $this->lineCostResolver->externalId($item);
                if ($externalId !== '') {
                    $line['externalId'] = $externalId;
                }

                if ($costsAllowed) {
                    $unitCost = $this->lineCostResolver->baseUnitCost($item);
                    if ($unitCost !== null) {
                        $line['unitCost'] = [
                            'amount'   => round($unitCost, 4),
                            'currency' => $orderCurrency,
                        ];
                    }
                }

                $products[] = $line;
            }
        }

        $revenueAmount = (float) $order->getGrandTotal();

        $data = [
            'client' => [
                'email' => (string) $order->getCustomerEmail(),
                'phone' => $billing !== null ? (string) $billing->getTelephone() : '',
            ],
            'products' => $products,
            'revenue' => [
                'amount'   => $revenueAmount,
                'currency' => $orderCurrency,
            ],
            'value' => $revenueAmount,
            'tax' => round((float) $order->getTaxAmount(), 4),
            'shipping' => round((float) $order->getShippingInclTax(), 4),
            'taxesIncluded' => true,
        ];

        $browser ??= BrowserIdentity::empty();

        // Browser and click ids, each omitted when it was not captured.
        foreach ($browser->signals() as $key => $value) {
            $data[$key] = $value;
        }

        // The visitor's Cookie Restriction Mode decision. AxiTrace's workspace consent
        // policy reads it from `data.consent` to decide whether this purchase may be
        // forwarded to the ad platforms. Omitted when the store runs without Cookie
        // Restriction Mode, and in every non-frontend context, so the worker sees
        // "no consent state" rather than a guessed one.
        if (
            $consent === CookieRestrictionConsentResolver::DECISION_GRANTED
            || $consent === CookieRestrictionConsentResolver::DECISION_DENIED
        ) {
            $data['consent'] = $consent;
        }

        $event = [
            'event'                 => 'transaction.charge',
            'eventSalt'             => $eventIdHash,
            'event_id'              => $eventIdHash,
            'transactionId'         => $eventIdHash,
            'orderId'               => (string) $order->getEntityId(),
            'incrementId'           => (string) $order->getIncrementId(),
            'workspace_public_key'  => $workspacePublicKey,
            'source'                => self::SOURCE,
            'timestamp'             => gmdate('Y-m-d\TH:i:s\Z'),
            'ip'                    => $browser->ip() !== ''
                ? $browser->ip()
                : (string) ($order->getRemoteIp() ?? ''),
            'userAgent'             => $browser->userAgent(),
            'pluginVersion'         => self::PLUGIN_VERSION,
            'sdkVersion'            => self::SDK_VERSION,
            'billingCity'           => $billing !== null ? (string) $billing->getCity() : '',
            'billingCountry'        => $billing !== null ? (string) $billing->getCountryId() : '',
            'billingZip'            => $billing !== null ? (string) $billing->getPostcode() : '',
            'data'                  => $data,
        ];

        if ($browser->visitorId() !== '') {
            $event['userId'] = $browser->visitorId();
        }
        if ($browser->sessionId() !== '') {
            $event['sessionId'] = $browser->sessionId();
        }

        return $event;
    }

    /**
     * Magento keeps product costs in the base currency. They may be sent only when
     * the order was placed in that same currency; a cost converted here with the
     * order's own rate would be a guess AxiTrace could not tell from a real cost.
     */
    private function costCurrencyMatches(OrderInterface $order, string $orderCurrency): bool
    {
        $baseCurrency = (string) $order->getBaseCurrencyCode();

        return $orderCurrency !== '' && strtoupper($baseCurrency) === strtoupper($orderCurrency);
    }
}
