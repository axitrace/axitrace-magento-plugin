<?php

declare(strict_types=1);

namespace AxiTrace\Tracking\Model\Identity;

use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Header as HttpHeader;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\Stdlib\CookieManagerInterface;

/**
 * Reads the shopper's browser identity from the current Magento request.
 *
 * Only a request sent by the shopper's own browser may be read: in any other request
 * the cookies, the IP and the User-Agent belong to someone else (the merchant in the
 * admin, a payment provider's webhook, an ERP calling the REST API, the cron). Two
 * conditions, both required:
 *   1. the area is one a storefront serves: `frontend` (Luma and Hyva pages, payment
 *      return controllers), `webapi_rest` (the Luma checkout places the order through
 *      the REST API from the browser) or `graphql`;
 *   2. the request carries a Cookie header at all. A browser on the storefront always
 *      sends one (the session cookie at the very least); a server-to-server call, a
 *      payment webhook included, does not.
 * Anything else returns null: "this request says nothing about the shopper".
 */
class BrowserIdentityCapture
{
    /** Areas in which the request can come from the shopper's browser. */
    public const BROWSER_AREAS = ['frontend', 'webapi_rest', 'graphql'];

    public function __construct(
        private readonly State $appState,
        private readonly CookieManagerInterface $cookieManager,
        private readonly HttpRequest $request,
        private readonly RemoteAddress $remoteAddress,
        private readonly HttpHeader $httpHeader,
        private readonly BrowserIdentityExtractor $extractor,
    ) {
    }

    /**
     * The identity carried by the current request, or null when the request is not
     * the shopper's browser (see the class docblock).
     */
    public function capture(): ?BrowserIdentity
    {
        if (!$this->isShopperBrowserRequest()) {
            return null;
        }

        $remoteAddress = $this->remoteAddress->getRemoteAddress();

        return $this->extractor->extract(
            fn (string $name): ?string => $this->stringOrNull($this->cookieManager->getCookie($name)),
            fn (string $name): ?string => $this->stringOrNull($this->request->getQueryValue($name)),
            is_string($remoteAddress) ? $remoteAddress : null,
            $this->stringOrNull($this->httpHeader->getHttpUserAgent()),
            (int) floor(microtime(true) * 1000),
        );
    }

    private function isShopperBrowserRequest(): bool
    {
        try {
            $areaCode = $this->appState->getAreaCode();
        } catch (LocalizedException $notSet) {
            // No area code: a CLI or cron process, never the shopper's browser.
            return false;
        }

        if (!in_array($areaCode, self::BROWSER_AREAS, true)) {
            return false;
        }

        $cookieHeader = $this->request->getServerValue('HTTP_COOKIE');

        return is_string($cookieHeader) && trim($cookieHeader) !== '';
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
