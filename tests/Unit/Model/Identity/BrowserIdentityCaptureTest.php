<?php

declare(strict_types=1);

namespace AxiTrace\Tracking\Tests\Unit\Model\Identity;

// Stand-ins for the Magento classes mocked below, so this test also runs without a
// Magento installation. No-op when the real framework is autoloadable (CI).
require_once __DIR__ . '/../../magento-stubs.php';

use AxiTrace\Tracking\Model\Identity\BrowserIdentityCapture;
use AxiTrace\Tracking\Model\Identity\BrowserIdentityExtractor;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Header as HttpHeader;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\Phrase;
use Magento\Framework\Stdlib\CookieManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Only the shopper's own browser request may be read: in the admin, in a payment
 * webhook or from an ERP calling the REST API, the cookies, IP and User-Agent belong
 * to someone else and must never be attached to the buyer's purchase.
 */
class BrowserIdentityCaptureTest extends TestCase
{
    private const VISITOR = '6f1c2a54-3b1e-4e8f-9d7a-0c2b4e6f8a10';
    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/129.0 Safari/537.36';

    public function testLumaCheckoutThroughRestApiIsCaptured(): void
    {
        $identity = $this->capture('webapi_rest', 'PHPSESSID=abc; vt_vid=' . self::VISITOR);

        self::assertNotNull($identity);
        self::assertSame(self::VISITOR, $identity->visitorId());
        self::assertSame('198.51.100.23', $identity->ip());
        self::assertSame(self::UA, $identity->userAgent());
        self::assertSame('fb.1.1700000000000.1234567890', $identity->signals()['fbp']);
    }

    public function testFrontendAreaIsCaptured(): void
    {
        self::assertNotNull($this->capture('frontend', 'PHPSESSID=abc'));
    }

    public function testGraphqlAreaIsCaptured(): void
    {
        self::assertNotNull($this->capture('graphql', 'PHPSESSID=abc'));
    }

    public function testAdminAreaIsNeverCaptured(): void
    {
        // The merchant invoicing in the admin has cookies too - they are not the buyer's.
        self::assertNull($this->capture('adminhtml', 'admin=xyz; _fbp=fb.1.1.1'));
    }

    public function testCronAreaIsNeverCaptured(): void
    {
        self::assertNull($this->capture('crontab', 'PHPSESSID=abc'));
    }

    public function testRequestWithoutCookieHeaderIsNotABrowser(): void
    {
        // A payment webhook or an ERP REST call reaches a storefront area without cookies.
        self::assertNull($this->capture('frontend', null));
        self::assertNull($this->capture('webapi_rest', '   '));
    }

    public function testUnsetAreaCodeIsNotABrowser(): void
    {
        $state = $this->createMock(State::class);
        $state->method('getAreaCode')->willThrowException(
            new LocalizedException(new Phrase('Area code is not set'))
        );

        self::assertNull($this->buildCapture($state, 'PHPSESSID=abc')->capture());
    }

    private function capture(string $area, ?string $cookieHeader): ?\AxiTrace\Tracking\Model\Identity\BrowserIdentity
    {
        $state = $this->createMock(State::class);
        $state->method('getAreaCode')->willReturn($area);

        return $this->buildCapture($state, $cookieHeader)->capture();
    }

    private function buildCapture(State $state, ?string $cookieHeader): BrowserIdentityCapture
    {
        $cookies = $this->createMock(CookieManagerInterface::class);
        $cookies->method('getCookie')->willReturnCallback(
            static fn (string $name): ?string => [
                'vt_vid' => self::VISITOR,
                '_fbp'   => 'fb.1.1700000000000.1234567890',
            ][$name] ?? null
        );

        $request = $this->createMock(HttpRequest::class);
        $request->method('getServerValue')->willReturnCallback(
            static fn (string $name) => $name === 'HTTP_COOKIE' ? $cookieHeader : null
        );
        $request->method('getQueryValue')->willReturn(null);

        $remote = $this->createMock(RemoteAddress::class);
        $remote->method('getRemoteAddress')->willReturn('198.51.100.23');

        $header = $this->createMock(HttpHeader::class);
        $header->method('getHttpUserAgent')->willReturn(self::UA);

        return new BrowserIdentityCapture(
            $state,
            $cookies,
            $request,
            $remote,
            $header,
            new BrowserIdentityExtractor()
        );
    }
}
