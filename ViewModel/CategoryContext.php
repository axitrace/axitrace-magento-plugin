<?php

declare(strict_types=1);

namespace AxiTrace\Tracking\ViewModel;

use Magento\Catalog\Model\Category;
use Magento\Framework\Registry;
use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * Exposes the current category on category page for view_category events.
 */
class CategoryContext implements ArgumentInterface
{
    public function __construct(
        private readonly Registry $registry,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getCategoryPayload(): array
    {
        $category = $this->registry->registry('current_category');
        if (!$category instanceof Category) {
            return [];
        }

        return [
            'categoryId'   => (string) $category->getId(),
            'categoryName' => (string) $category->getName(),
            'categoryPath' => (string) $category->getPath(),
        ];
    }
}
