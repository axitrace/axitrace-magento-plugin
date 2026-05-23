<?php

declare(strict_types=1);

namespace AxiTrace\Tracking\Model\Normalizer;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;

/**
 * Builds the GeneratedEvent-shaped payload that ingestion-api expects.
 *
 * Output shape mirrors WooCommerceEventNormalizer output in the velitrack repo:
 *   {
 *     event, eventSalt, transactionId, orderId, incrementId,
 *     workspace_public_key, source: "magento", timestamp, ip, userAgent,
 *     pluginVersion, sdkVersion,
 *     billingCity, billingCountry, billingZip,
 *     data: {
 *       client: { email, phone },
 *       products: [{ productId, sku, name, quantity, price, currency }],
 *       revenue: { amount, currency },
 *       value: <float>
 *     }
 *   }
 *
 * PII is forwarded in plain text per project memory — the Facebook CAPI PHP SDK
 * and TikTok Events API auto-hash; only `external_id` requires manual SHA-256.
 *
 * Currency: read from $order->getOrderCurrencyCode() (presentation currency),
 * not base currency — matches AstrophotoMarket lesson logged in project memory.
 */
class OrderEventNormalizer
{
    private const PLUGIN_VERSION = '0.1.0';
    private const SDK_VERSION    = 'magento-1.0';
    private const SOURCE         = 'magento';

    /**
     * @return array<string, mixed>
     */
    public function normalize(OrderInterface $order, string $eventIdHash, string $workspacePublicKey): array
    {
        $billing = $order->getBillingAddress();
        $orderCurrency = (string) $order->getOrderCurrencyCode();

        $products = [];
        foreach ($order->getAllVisibleItems() as $item) {
            if ($item instanceof OrderItemInterface) {
                $products[] = [
                    'productId' => (string) $item->getProductId(),
                    'sku'       => (string) $item->getSku(),
                    'name'      => (string) $item->getName(),
                    'quantity'  => (float) $item->getQtyOrdered(),
                    'price'     => (float) $item->getPrice(),
                    'currency'  => $orderCurrency,
                ];
            }
        }

        $revenueAmount = (float) $order->getGrandTotal();

        return [
            'event'                 => 'transaction.charge',
            'eventSalt'             => $eventIdHash,
            'event_id'              => $eventIdHash,
            'transactionId'         => $eventIdHash,
            'orderId'               => (string) $order->getEntityId(),
            'incrementId'           => (string) $order->getIncrementId(),
            'workspace_public_key'  => $workspacePublicKey,
            'source'                => self::SOURCE,
            'timestamp'             => gmdate('Y-m-d\TH:i:s\Z'),
            'ip'                    => (string) ($order->getRemoteIp() ?? ''),
            'userAgent'             => '',
            'pluginVersion'         => self::PLUGIN_VERSION,
            'sdkVersion'            => self::SDK_VERSION,
            'billingCity'           => $billing !== null ? (string) $billing->getCity() : '',
            'billingCountry'        => $billing !== null ? (string) $billing->getCountryId() : '',
            'billingZip'            => $billing !== null ? (string) $billing->getPostcode() : '',
            'data' => [
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
            ],
        ];
    }
}
