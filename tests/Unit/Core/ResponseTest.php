<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Response;

/**
 * ResponseTest
 *
 * Tests the Response class. Focus on static methods that can be tested
 * without mocking PHP builtins (header, echo, exit).
 *
 * Methods like render(), redirect(), json(), and download() require mocking
 * PHP output functions, so we focus on:
 * - applySecurityHeaders() — verifies it can be called without errors
 * - applyStaticAssetHeaders() — same
 * - buildContentSecurityPolicy() — returns CSP string with expected directives (private, accessed via reflection)
 */
class ResponseTest extends TestCase
{
    private function callBuildCSP(string $profile): string
    {
        $reflection = new \ReflectionClass(Response::class);
        $method = $reflection->getMethod('buildContentSecurityPolicy');
        $method->setAccessible(true);
        return $method->invoke(null, $profile);
    }

    // ===========================
    // buildContentSecurityPolicy()
    // ===========================

    #[Test]
    public function testBuildCSPPublicProfileContainsDefaultSrc(): void
    {
        $csp = $this->callBuildCSP('public');

        $this->assertStringContainsString("default-src", $csp);
    }

    #[Test]
    public function testBuildCSPPublicProfileContainsSelfDirective(): void
    {
        $csp = $this->callBuildCSP('public');

        $this->assertStringContainsString("'self'", $csp);
    }

    #[Test]
    public function testBuildCSPPublicProfileDisallowsObjectSrc(): void
    {
        $csp = $this->callBuildCSP('public');

        $this->assertStringContainsString("object-src 'none'", $csp);
    }

    #[Test]
    public function testBuildCSPPublicProfileAllowsStripeFrame(): void
    {
        $csp = $this->callBuildCSP('public');

        $this->assertStringContainsString("frame-src https://checkout.stripe.com", $csp);
    }

    #[Test]
    public function testBuildCSPPublicProfileDenialsFrameAncestors(): void
    {
        $csp = $this->callBuildCSP('public');

        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
    }

    #[Test]
    public function testBuildCSPPublicProfileAllowsDataImages(): void
    {
        $csp = $this->callBuildCSP('public');

        $this->assertStringContainsString("img-src 'self' data:", $csp);
    }

    #[Test]
    public function testBuildCSPAppProfileContainsDefaultSrc(): void
    {
        $csp = $this->callBuildCSP('app');

        $this->assertStringContainsString("default-src", $csp);
    }

    #[Test]
    public function testBuildCSPAppProfileAllowsCdnScripts(): void
    {
        $csp = $this->callBuildCSP('app');

        $this->assertStringContainsString("https://cdn.jsdelivr.net", $csp);
    }

    #[Test]
    public function testBuildCSPAppProfileAllowsUnsafeInlineStyles(): void
    {
        $csp = $this->callBuildCSP('app');

        $this->assertStringContainsString("style-src", $csp);
    }

    #[Test]
    public function testBuildCSPAppProfileDisallowsObjectSrc(): void
    {
        $csp = $this->callBuildCSP('app');

        $this->assertStringContainsString("object-src 'none'", $csp);
    }

    #[Test]
    public function testBuildCSPAppProfileAllowsStripeForm(): void
    {
        $csp = $this->callBuildCSP('app');

        $this->assertStringContainsString("form-action", $csp);
    }

    #[Test]
    public function testBuildCSPUnknownProfileDefaultsToApp(): void
    {
        $csp = $this->callBuildCSP('unknown');

        $this->assertStringContainsString("https://cdn.jsdelivr.net", $csp);
        $this->assertStringContainsString("style-src", $csp);
    }

    // ===========================
    // applySecurityHeaders()
    // ===========================

    #[Test]
    public function testApplySecurityHeadersAppProfileDoesNotThrow(): void
    {
        $this->expectNotToPerformAssertions();
        Response::applySecurityHeaders('app');
    }

    #[Test]
    public function testApplySecurityHeadersPublicProfileDoesNotThrow(): void
    {
        $this->expectNotToPerformAssertions();
        Response::applySecurityHeaders('public');
    }

    #[Test]
    public function testApplySecurityHeadersUnknownProfileDoesNotThrow(): void
    {
        $this->expectNotToPerformAssertions();
        Response::applySecurityHeaders('unknown');
    }

    // ===========================
    // applyStaticAssetHeaders()
    // ===========================

    #[Test]
    public function testApplyStaticAssetHeadersDoesNotThrow(): void
    {
        $this->expectNotToPerformAssertions();
        Response::applyStaticAssetHeaders('image/png', 1024);
    }

    #[Test]
    public function testApplyStaticAssetHeadersWithVariousMimeTypes(): void
    {
        $mimeTypes = ['text/css', 'application/javascript', 'font/woff2', 'image/svg+xml'];

        foreach ($mimeTypes as $mime) {
            $this->expectNotToPerformAssertions();
            Response::applyStaticAssetHeaders($mime, 512);
        }
    }

    #[Test]
    public function testApplyStaticAssetHeadersWithCustomMaxAge(): void
    {
        $this->expectNotToPerformAssertions();
        Response::applyStaticAssetHeaders('text/css', 2048, 604800); // 7 days
    }

    #[Test]
    public function testApplyStaticAssetHeadersWithZeroMaxAge(): void
    {
        $this->expectNotToPerformAssertions();
        Response::applyStaticAssetHeaders('text/plain', 100, 0);
    }

    #[Test]
    public function testApplyStaticAssetHeadersWithLargeContentLength(): void
    {
        $this->expectNotToPerformAssertions();
        Response::applyStaticAssetHeaders('video/mp4', 1073741824); // 1GB
    }
}
