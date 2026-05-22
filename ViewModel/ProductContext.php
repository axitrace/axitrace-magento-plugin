<?php

declare(strict_types=1);

namespace AxiTrace\Tracking\ViewModel;

use Magento\Catalog\Model\Product;
use Magento\Framework\Registry;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Exposes the current product on PDP for view_content events.
 */
class ProductContext implements ArgumentInterface
{
    public function __construct(
        private readonly Registry $registry,
        private readonly StoreManagerInterface $storeManager,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getProductPayload(): array
    {
        $product = $this->registry->registry('current_product');
        if (!$product instanceof Product) {
            return [];
        }

        try {
            $currency = (string) $this->storeManager->getStore()->getCurrentCurrencyCode();
        } catch (\Throwable) {
            $currency = 'USD';
        }

        return [
            'productId' => (string) $product->getId(),
            'sku'       => (string) $product->getSku(),
            'name'      => (string) $product->getName(),
            'price'     => (float) $product->getFinalPrice(),
            'currency'  => $currency,
        ];
    }
}
