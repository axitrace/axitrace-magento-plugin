<?php

declare(strict_types=1);

namespace AxiTrace\Tracking\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Renders the "Test Connection" button in the AxiTrace system config form.
 *
 * Click handler triggers an AJAX call to the admin TestConnection controller
 * (axitrace/system/testconnection) using the current value of the
 * workspace_public_key field. The result is rendered into the StatusIndicator
 * element directly below — no full form save required.
 */
class TestConnectionButton extends Field
{
    /**
     * @var string
     */
    protected $_template = 'AxiTrace_Tracking::system/config/test_connection.phtml';

    public function __construct(
        Context $context,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        $this->addData([
            'button_label' => __('Test Connection'),
            'html_id'      => $element->getHtmlId(),
            'ajax_url'     => $this->getUrl('axitrace/system/testconnection'),
        ]);

        return $this->_toHtml();
    }
}
