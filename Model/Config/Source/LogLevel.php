<?php

declare(strict_types=1);

namespace AxiTrace\Tracking\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Source model for the log level dropdown (Advanced group).
 */
class LogLevel implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'error',   'label' => __('Error')],
            ['value' => 'warning', 'label' => __('Warning')],
            ['value' => 'info',    'label' => __('Info')],
            ['value' => 'debug',   'label' => __('Debug')],
        ];
    }
}
