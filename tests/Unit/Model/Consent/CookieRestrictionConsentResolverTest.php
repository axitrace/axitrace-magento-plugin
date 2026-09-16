<?php

declare(strict_types=1);

namespace AxiTrace\Tracking\Tests\Unit\Model\Consent;

use AxiTrace\Tracking\Model\Consent\CookieRestrictionConsentResolver;
use PHPUnit\Framework\TestCase;

/**
 * Every branch of the Cookie Restriction Mode decision.
 *
 * The resolver references no Magento class, so this test runs with nothing but a
 * PSR-4 autoloader for the module (tests/Unit/bootstrap.php).
 */
class CookieRestrictionConsentResolverTest extends TestCase
{
    private CookieRestrictionConsentResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new CookieRestrictionConsentResolver();
    }

    public function testRestrictionDisabledProducesNoConsentState(): void
    {
        // The store asks for no consent, so the workspace policy decides, not the shop.
        self::assertNull($this->resolver->resolve(false, '{"1":1}', 'frontend', 1));
        self::assertNull($this->resolver->resolve(false, null, 'frontend', 1));
    }

    public function testRestrictionEnabledAndCookieForThisWebsiteGrants(): void
    {
        self::assertSame(
            'granted',
            $this->resolver->resolve(true, '{"1":1}', 'frontend', 1)
        );
    }

    public function testRestrictionEnabledAndNoCookieDenies(): void
    {
        self::assertSame(
            'denied',
            $this->resolver->resolve(true, null, 'frontend', 1)
        );
    }

    public function testEmptyCookieDenies(): void
    {
        self::assertSame('denied', $this->resolver->resolve(true, '', 'frontend', 1));
        self::assertSame('denied', $this->resolver->resolve(true, '   ', 'frontend', 1));
    }

    public function testCookieForAnotherWebsiteDenies(): void
    {
        // Magento keys the cookie by website id; consent on website 1 is not consent
        // on website 2.
        self::assertSame(
            'denied',
            $this->resolver->resolve(true, '{"1":1}', 'frontend', 2)
        );
    }

    public function testCookieWithFalsyEntryForThisWebsiteDenies(): void
    {
        self::assertSame(
            'denied',
            $this->resolver->resolve(true, '{"1":0}', 'frontend', 1)
        );
    }

    public function testCookieWithSeveralWebsitesGrantsOnlyForTheTruthyOne(): void
    {
        $cookie = '{"1":1,"2":0}';

        self::assertSame('granted', $this->resolver->resolve(true, $cookie, 'frontend', 1));
        self::assertSame('denied', $this->resolver->resolve(true, $cookie, 'frontend', 2));
        self::assertSame('denied', $this->resolver->resolve(true, $cookie, 'frontend', 3));
    }

    public function testUrlEncodedCookieIsDecodedBeforeTheDecision(): void
    {
        // %7B%221%22%3A1%7D is {"1":1}
        self::assertSame(
            'granted',
            $this->resolver->resolve(true, '%7B%221%22%3A1%7D', 'frontend', 1)
        );
    }

    public function testBareJsonScalarCookie(): void
    {
        self::assertSame('granted', $this->resolver->resolve(true, '1', 'frontend', 1));
        self::assertSame('granted', $this->resolver->resolve(true, 'true', 'frontend', 1));
        self::assertSame('denied', $this->resolver->resolve(true, '0', 'frontend', 1));
        self::assertSame('denied', $this->resolver->resolve(true, 'false', 'frontend', 1));
        self::assertSame('denied', $this->resolver->resolve(true, 'null', 'frontend', 1));
    }

    public function testNonJsonCookieGrantsUnlessItSpellsRefusal(): void
    {
        self::assertSame('granted', $this->resolver->resolve(true, 'yes', 'frontend', 1));
        self::assertSame('denied', $this->resolver->resolve(true, 'no', 'frontend', 1));
        self::assertSame('denied', $this->resolver->resolve(true, 'DENIED', 'frontend', 1));
        self::assertSame('denied', $this->resolver->resolve(true, 'opt-out', 'frontend', 1));
    }

    /**
     * @dataProvider nonFrontendAreas
     */
    public function testNonFrontendAreaNeverStampsConsent(?string $areaCode): void
    {
        // An admin invoice, a payment webhook and a cron run carry no buyer cookies,
        // so a missing cookie proves nothing: stamp nothing, never `denied`.
        self::assertNull($this->resolver->resolve(true, null, $areaCode, 1));
        self::assertNull($this->resolver->resolve(true, '{"1":1}', $areaCode, 1));
    }

    /**
     * @return array<string, array{0: string|null}>
     */
    public static function nonFrontendAreas(): array
    {
        return [
            'admin'       => ['adminhtml'],
            'webapi rest' => ['webapi_rest'],
            'webapi soap' => ['webapi_soap'],
            'crontab'     => ['crontab'],
            'global'      => ['global'],
            'unset area'  => [null],
        ];
    }

    public function testDecisionConstantsMatchTheValuesAxiTraceStores(): void
    {
        self::assertSame('granted', CookieRestrictionConsentResolver::DECISION_GRANTED);
        self::assertSame('denied', CookieRestrictionConsentResolver::DECISION_DENIED);
        self::assertSame('user_allowed_save_cookie', CookieRestrictionConsentResolver::COOKIE_NAME);
        self::assertSame(
            'web/cookie/cookie_restriction',
            CookieRestrictionConsentResolver::XML_PATH_COOKIE_RESTRICTION
        );
    }
}
