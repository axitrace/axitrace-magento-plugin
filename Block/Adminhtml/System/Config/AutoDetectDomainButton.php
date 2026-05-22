<?php

declare(strict_types=1);

namespace AxiTrace\Tracking\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Renders the "Auto-Detect Domain" button in the AxiTrace system config form.
 *
 * On click, calls the admin AutoDetectDomain controller which forwards the
 * workspace_public_key to /api/public/workspace/tracking-domain on event-worker
 * and populates the tracking_domain field with the returned value.
 */
class AutoDetectDomainButton extends Field
{
    /**
     * @var string
     */
    protected $_template = 'AxiTrace_Tracking::system/config/auto_detect_domain.phtml';

    public function __construct(
        Context $context,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        $this->addData([
            'button_label' => __('Auto-Detect Domain'),
            'html_id'      => $element->getHtmlId(),
            'ajax_url'     => $this->getUrl('axitrace/system/autodetectdomain'),
        ]);

        return $this->_toHtml();
    }
}
