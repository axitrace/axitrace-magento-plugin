<?php

declare(strict_types=1);

namespace AxiTrace\Tracking\Block\Adminhtml\System\Config;

use AxiTrace\Tracking\Model\Domain\DomainStatusMessages;
use AxiTrace\Tracking\Model\Domain\StoreDomainStatusStore;
use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Renders the store domain verification status in the AxiTrace system config
 * form (one line per storefront host) and the "Verify store domain" button,
 * which calls the admin VerifyStoreDomain controller and redraws the lines.
 */
class DomainVerificationStatus extends Field
{
    /**
     * @var string
     */
    protected $_template = 'AxiTrace_Tracking::system/config/domain_verification_status.phtml';

    public function __construct(
        Context $context,
        private readonly StoreDomainStatusStore $statusStore,
        private readonly DomainStatusMessages $messages,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @return list<array{level: string, text: string}>
     */
    public function getStatusLines(): array
    {
        return $this->messages->lines($this->statusStore->load());
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        $this->addData([
            'button_label' => __('Verify store domain'),
            'html_id'      => $element->getHtmlId(),
            'ajax_url'     => $this->getUrl('axitrace/system/verifystoredomain'),
        ]);

        return $this->_toHtml();
    }
}
