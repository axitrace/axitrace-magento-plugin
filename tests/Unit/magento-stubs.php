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
        /**
         * Mirrors DataObject's magic get/set so entity stand-ins (EventLog) can be
         * used as real objects in tests.
         */
        class AbstractModel
        {
            /** @var array<string, mixed> */
            protected array $stubData = [];

            /** @return mixed */
            public function getData(?string $key = null)
            {
                return $key === null ? $this->stubData : ($this->stubData[$key] ?? null);
            }

            /** @return $this */
            public function setData(string $key, mixed $value = null)
            {
                $this->stubData[$key] = $value;

                return $this;
            }

            /**
             * @param array<int, mixed> $args
             * @return mixed
             */
            public function __call(string $method, array $args)
            {
                $key = strtolower((string) preg_replace('/(.)([A-Z])/', '$1_$2', substr($method, 3)));
                if (str_starts_with($method, 'get')) {
                    return $this->getData($key);
                }
                if (str_starts_with($method, 'set')) {
                    return $this->setData($key, $args[0] ?? null);
                }

                throw new \BadMethodCallException($method);
            }
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

namespace Magento\Framework {
    if (!class_exists(Phrase::class)) {
        class Phrase
        {
            /** @param array<int|string, mixed> $arguments */
            public function __construct(private string $text, private array $arguments = [])
            {
            }

            public function render(): string
            {
                $result = $this->text;
                foreach ($this->arguments as $key => $value) {
                    $result = str_replace('%' . (is_int($key) ? $key + 1 : $key), (string) $value, $result);
                }

                return $result;
            }
        }
    }
}

namespace Magento\Framework\Exception {
    if (!class_exists(LocalizedException::class)) {
        class LocalizedException extends \Exception
        {
            public function __construct(\Magento\Framework\Phrase $phrase, ?\Throwable $cause = null, int $code = 0)
            {
                parent::__construct($phrase->render(), $code, $cause);
            }
        }
    }
}

namespace Magento\Framework\Stdlib\DateTime {
    if (!class_exists(DateTime::class)) {
        class DateTime
        {
            public function gmtDate($format = null, $input = null)
            {
                return gmdate($format ?? 'Y-m-d H:i:s');
            }
        }
    }
}

namespace Magento\Framework\HTTP\Client {
    if (!class_exists(Curl::class)) {
        class Curl
        {
            public function setOption($name, $value)
            {
            }

            public function addHeader($name, $value)
            {
            }

            public function post($uri, $params)
            {
            }

            public function get($uri)
            {
            }

            public function getStatus()
            {
                return 200;
            }

            public function getBody()
            {
                return '';
            }

            public function getHeaders()
            {
                return [];
            }
        }
    }

    // Magento generates this factory at runtime.
    if (!class_exists(CurlFactory::class)) {
        class CurlFactory
        {
            public function create(array $data = []): Curl
            {
                return new Curl();
            }
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

namespace Magento\Framework\Encryption {
    if (!interface_exists(EncryptorInterface::class)) {
        interface EncryptorInterface
        {
            /** @return string */
            public function decrypt($data);
        }
    }
}

namespace Magento\Framework\App {
    if (!class_exists(Area::class)) {
        class Area
        {
            public const AREA_FRONTEND = 'frontend';
            public const AREA_ADMINHTML = 'adminhtml';
        }
    }

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

namespace Magento\Framework\App\Request {
    if (!class_exists(Http::class)) {
        class Http
        {
            /** @return mixed */
            public function getQueryValue($name = null, $default = null)
            {
                return $default;
            }

            /** @return mixed */
            public function getServerValue($name = null, $default = null)
            {
                return $default;
            }
        }
    }
}

namespace Magento\Framework\HTTP\PhpEnvironment {
    if (!class_exists(RemoteAddress::class)) {
        class RemoteAddress
        {
            /** @return string|false */
            public function getRemoteAddress(bool $ipToLong = false)
            {
                return false;
            }
        }
    }
}

namespace Magento\Framework\HTTP {
    if (!class_exists(Header::class)) {
        class Header
        {
            /** @return string */
            public function getHttpUserAgent($clean = true)
            {
                return '';
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

            public function getBaseCurrencyCode();

            public function getTaxAmount();

            public function getShippingInclTax();

            public function getTotalCanceled();
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

            public function getBaseCost();

            public function getParentItem();

            public function getQtyCanceled();

            public function getRowTotalInclTax();

            public function getDiscountAmount();
        }
    }

    if (!interface_exists(CreditmemoInterface::class)) {
        interface CreditmemoInterface
        {
            public function getEntityId();

            public function getGrandTotal();

            public function getCreatedAt();

            public function getState();

            public function getItems();
        }
    }

    if (!interface_exists(CreditmemoItemInterface::class)) {
        interface CreditmemoItemInterface
        {
            public function getProductId();

            public function getSku();

            public function getQty();

            public function getRowTotalInclTax();

            public function getDiscountAmount();
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

            public function getBaseCurrencyCode()
            {
                return null;
            }

            public function getTaxAmount()
            {
                return null;
            }

            public function getShippingInclTax()
            {
                return null;
            }

            public function getTotalCanceled()
            {
                return null;
            }

            public function getId()
            {
                return null;
            }

            /** @return \Magento\Sales\Model\Order\Payment|null */
            public function getPayment()
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

namespace Magento\Sales\Model\Order {
    if (!class_exists(Payment::class)) {
        class Payment
        {
            public function getMethod()
            {
                return null;
            }
        }
    }
}

namespace Magento\Checkout\Model {
    if (!class_exists(Session::class)) {
        class Session
        {
            /** @return \Magento\Sales\Model\Order|null */
            public function getLastRealOrder()
            {
                return null;
            }
        }
    }
}

namespace Magento\Framework\View\Element\Block {
    if (!interface_exists(ArgumentInterface::class)) {
        interface ArgumentInterface
        {
        }
    }
}

namespace Magento\Catalog\Model {
    if (!class_exists(Product::class)) {
        class Product extends \Magento\Framework\Model\AbstractModel
        {
        }
    }
}

namespace Magento\Sales\Model\Order {
    if (!class_exists(Item::class)) {
        class Item extends \Magento\Framework\Model\AbstractModel implements
            \Magento\Sales\Api\Data\OrderItemInterface
        {
            public function getProductId() { return null; }
            public function getSku() { return null; }
            public function getName() { return null; }
            public function getQtyOrdered() { return null; }
            public function getPrice() { return null; }
            public function getBaseCost() { return null; }
            public function getParentItem() { return null; }
            public function getQtyCanceled() { return null; }
            public function getRowTotalInclTax() { return null; }
            public function getDiscountAmount() { return null; }

            /** @return array<int, \Magento\Sales\Model\Order\Item> */
            public function getChildrenItems() { return []; }

            /** @return \Magento\Catalog\Model\Product|null */
            public function getProduct() { return null; }
        }
    }

    if (!class_exists(Creditmemo::class)) {
        class Creditmemo extends \Magento\Framework\Model\AbstractModel implements
            \Magento\Sales\Api\Data\CreditmemoInterface
        {
            public const STATE_OPEN     = 1;
            public const STATE_REFUNDED = 2;
            public const STATE_CANCELED = 3;

            public function getEntityId() { return null; }
            public function getGrandTotal() { return null; }
            public function getCreatedAt() { return null; }
            public function getState() { return null; }
            public function getItems() { return []; }

            /** @return \Magento\Sales\Model\Order|null */
            public function getOrder() { return null; }
        }
    }
}

namespace Magento\Sales\Model\Order\Creditmemo {
    if (!class_exists(Item::class)) {
        class Item extends \Magento\Framework\Model\AbstractModel implements
            \Magento\Sales\Api\Data\CreditmemoItemInterface
        {
            public function getProductId() { return null; }
            public function getSku() { return null; }
            public function getQty() { return null; }
            public function getRowTotalInclTax() { return null; }
            public function getDiscountAmount() { return null; }

            /** @return \Magento\Sales\Model\Order\Item|null */
            public function getOrderItem() { return null; }
        }
    }
}

namespace Magento\Csp\Api {
    if (!interface_exists(PolicyCollectorInterface::class)) {
        interface PolicyCollectorInterface
        {
            public function collect(array $defaultPolicies = []): array;
        }
    }
}

namespace Magento\Csp\Model\Policy {
    if (!class_exists(FetchPolicy::class)) {
        class FetchPolicy
        {
            public function __construct(
                private string $id,
                private bool $noneAllowed = true,
                private array $hostSources = [],
            ) {
            }

            public function getId(): string
            {
                return $this->id;
            }

            public function isNoneAllowed(): bool
            {
                return $this->noneAllowed;
            }

            public function getHostSources(): array
            {
                return $this->hostSources;
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
