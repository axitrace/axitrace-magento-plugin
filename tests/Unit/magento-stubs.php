<?php

declare(strict_types=1);

/**
 * Minimal stand-ins for the Magento classes the Magento-coupled unit tests mock.
 *
 * Every declaration is guarded, so this file is a no-op when the real Magento
 * framework is installed (CI runs the same tests against `vendor/autoload.php`,
 * where the real classes win). It exists only so the wiring tests can also run
 * locally, where the module has no `vendor/` directory of its own.
 *
 * Nothing here is used by production code, and the framework-free tests
 * (Model/Consent) never load this file.
 */

namespace Magento\Framework\Model {
    if (!class_exists(AbstractModel::class)) {
        class AbstractModel
        {
        }
    }
}

namespace Magento\Framework {
    if (!class_exists(Event::class)) {
        class Event
        {
            /** @return mixed */
            public function getData(?string $key = null)
            {
                return null;
            }
        }
    }
}

namespace Magento\Framework\Event {
    if (!class_exists(Observer::class)) {
        class Observer
        {
            public function getEvent(): \Magento\Framework\Event
            {
                return new \Magento\Framework\Event();
            }
        }
    }

    if (!interface_exists(ObserverInterface::class)) {
        interface ObserverInterface
        {
            public function execute(Observer $observer);
        }
    }
}

namespace Magento\Framework\Stdlib {
    if (!interface_exists(CookieManagerInterface::class)) {
        interface CookieManagerInterface
        {
            /** @return string|null */
            public function getCookie($name, $default = null);
        }
    }
}

namespace Magento\Framework\App\Config {
    if (!interface_exists(ScopeConfigInterface::class)) {
        interface ScopeConfigInterface
        {
            /** @return mixed */
            public function getValue($path, $scopeType = 'default', $scopeCode = null);

            public function isSetFlag($path, $scopeType = 'default', $scopeCode = null);
        }
    }
}

namespace Magento\Framework\App {
    if (!class_exists(State::class)) {
        class State
        {
            public function getAreaCode(): string
            {
                return 'frontend';
            }
        }
    }
}

namespace Magento\Framework\MessageQueue {
    if (!interface_exists(PublisherInterface::class)) {
        interface PublisherInterface
        {
            public function publish($topicName, $data);
        }
    }
}

namespace Magento\Store\Model {
    if (!interface_exists(ScopeInterface::class)) {
        interface ScopeInterface
        {
            public const SCOPE_STORE    = 'store';
            public const SCOPE_STORES   = 'stores';
            public const SCOPE_WEBSITE  = 'website';
            public const SCOPE_WEBSITES = 'websites';
        }
    }

    if (!interface_exists(StoreManagerInterface::class)) {
        interface StoreManagerInterface
        {
            /** @return \Magento\Store\Api\Data\StoreInterface */
            public function getStore($storeId = null);
        }
    }
}

namespace Magento\Store\Api\Data {
    if (!interface_exists(StoreInterface::class)) {
        interface StoreInterface
        {
            public function getId();

            public function getWebsiteId();
        }
    }
}

namespace Magento\Sales\Api\Data {
    if (!interface_exists(OrderInterface::class)) {
        interface OrderInterface
        {
            public function getEntityId();

            public function getIncrementId();

            public function getStoreId();

            public function getState();

            public function getGrandTotal();

            public function getOrderCurrencyCode();

            public function getCustomerEmail();

            public function getRemoteIp();

            public function getBillingAddress();
        }
    }

    if (!interface_exists(OrderItemInterface::class)) {
        interface OrderItemInterface
        {
            public function getProductId();

            public function getSku();

            public function getName();

            public function getQtyOrdered();

            public function getPrice();
        }
    }
}

namespace Magento\Sales\Api {
    if (!interface_exists(OrderRepositoryInterface::class)) {
        interface OrderRepositoryInterface
        {
            /** @return \Magento\Sales\Api\Data\OrderInterface */
            public function get($id);
        }
    }
}

namespace Magento\Sales\Model {
    if (!class_exists(Order::class)) {
        class Order extends \Magento\Framework\Model\AbstractModel implements
            \Magento\Sales\Api\Data\OrderInterface
        {
            public const STATE_NEW        = 'new';
            public const STATE_PROCESSING = 'processing';
            public const STATE_COMPLETE   = 'complete';

            /** @return mixed */
            public function getOrigData($key = null)
            {
                return null;
            }

            public function getEntityId()
            {
                return null;
            }

            public function getIncrementId()
            {
                return null;
            }

            public function getStoreId()
            {
                return null;
            }

            public function getState()
            {
                return null;
            }

            public function getGrandTotal()
            {
                return null;
            }

            public function getOrderCurrencyCode()
            {
                return null;
            }

            public function getCustomerEmail()
            {
                return null;
            }

            public function getRemoteIp()
            {
                return null;
            }

            public function getBillingAddress()
            {
                return null;
            }

            /** @return array<int, \Magento\Sales\Api\Data\OrderItemInterface> */
            public function getAllVisibleItems(): array
            {
                return [];
            }
        }
    }
}

namespace AxiTrace\Tracking\Model\EventLog {
    // Magento generates this factory at runtime; it has no source file in the module.
    if (!class_exists(EventLogFactory::class)) {
        class EventLogFactory
        {
            /**
             * @param array<string, mixed> $data
             */
            public function create(array $data = []): EventLog
            {
                return new EventLog();
            }
        }
    }
}
