<?php

declare(strict_types=1);

namespace AxiTrace\Tracking\Block\Adminhtml\System\Config;

use AxiTrace\Tracking\Api\EventLogRepositoryInterface;
use AxiTrace\Tracking\Model\EventLog\EventLog;
use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Renders a coloured status dot reflecting the most recent forwarded event.
 *
 *   green  — last event sent <24h ago (status=sent)
 *   yellow — no events captured yet (table empty)
 *   red    — the most recent attempt failed (most recent row status=failed)
 *
 * Reads from axitrace_event_log via the repository — no direct SQL.
 */
class StatusIndicator extends Field
{
    public function __construct(
        Context $context,
        private readonly EventLogRepositoryInterface $eventLogRepo,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        $row = $this->eventLogRepo->findLastSuccess();

        if ($row === null) {
            return $this->renderDot('#d4a017', __('No events captured yet. Place a test order to verify the connection.'));
        }

        $sentAt = $row->getSentAt();
        if ($sentAt === null) {
            return $this->renderDot('#d4a017', __('No completed events yet.'));
        }

        $ageSeconds = max(0, time() - (int) strtotime($sentAt . ' UTC'));
        $minutesAgo = (int) floor($ageSeconds / 60);

        if ($ageSeconds <= 86400) {
            $label = $minutesAgo <= 1
                ? __('Connected — last event %1 just now', '')
                : __('Connected — last event %1 minutes ago', $minutesAgo);
            return $this->renderDot('#28a745', $label);
        }

        return $this->renderDot('#dc3545', __('No recent successful event (last success was over 24h ago).'));
    }

    private function renderDot(string $hexColor, $label): string
    {
        return sprintf(
            '<span style="display:inline-block;width:10px;height:10px;border-radius:50%%;background:%s;margin-right:8px;vertical-align:middle"></span><span style="vertical-align:middle">%s</span>',
            htmlspecialchars($hexColor, ENT_QUOTES, 'UTF-8'),
            (string) $label
        );
    }
}
