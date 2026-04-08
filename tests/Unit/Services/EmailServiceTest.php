<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Services\EmailService;

/**
 * EmailServiceTest
 *
 * Tests instantiation and HTML output for EmailService.
 * Uses a testable subclass to expose the private buildEmailHtml method.
 * Actual mail delivery is not tested (PHP's mail() requires a configured MTA).
 */
class EmailServiceTest extends TestCase
{
    // ===========================
    // HELPERS
    // ===========================

    /**
     * Build a testable EmailService instance with stub config.
     */
    private function makeService(): EmailService
    {
        return new EmailService([
            'mail' => [
                'from_name'  => 'StratFlow Test',
                'from_email' => 'test@stratflow.app',
            ],
        ]);
    }

    /**
     * Build a testable subclass that exposes buildEmailHtml publicly.
     */
    private function makeTestableService(): object
    {
        return new class(['mail' => ['from_name' => 'StratFlow Test', 'from_email' => 'test@stratflow.app']]) extends EmailService {
            public function buildHtml(
                string $heading,
                string $bodyHtml,
                string $buttonText,
                string $buttonUrl,
                string $footerText
            ): string {
                // Call the parent's private method via Closure binding
                $fn = \Closure::bind(
                    fn() => $this->buildEmailHtml($heading, $bodyHtml, $buttonText, $buttonUrl, $footerText),
                    $this,
                    EmailService::class
                );
                return $fn();
            }
        };
    }

    // ===========================
    // INSTANTIATION
    // ===========================

    #[Test]
    public function testServiceCanBeInstantiated(): void
    {
        $service = $this->makeService();
        $this->assertInstanceOf(EmailService::class, $service);
    }

    // ===========================
    // HTML BUILDING
    // ===========================

    #[Test]
    public function testSendWelcomeBuildsCorrectHtml(): void
    {
        $service = $this->makeTestableService();

        $html = $service->buildHtml(
            'Welcome to StratFlow, Alice!',
            '<p>Your account has been created.</p>',
            'Set Your Password',
            'https://app.stratflow.com/set-password?token=abc123',
            'This link expires in 24 hours.'
        );

        $this->assertStringContainsString('Welcome to StratFlow, Alice!', $html);
        $this->assertStringContainsString('Set Your Password', $html);
        $this->assertStringContainsString('https://app.stratflow.com/set-password?token=abc123', $html);
        $this->assertStringContainsString('This link expires in 24 hours.', $html);
        $this->assertStringContainsString('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('StratFlow', $html);
    }

    #[Test]
    public function testSendPasswordResetBuildsHtml(): void
    {
        $service = $this->makeTestableService();

        $html = $service->buildHtml(
            'Reset Your Password',
            '<p>Hi Bob, we received a request to reset your password.</p>',
            'Reset Password',
            'https://app.stratflow.com/reset-password?token=xyz789',
            "This link expires in 24 hours. If you didn't request a password reset, you can safely ignore this email."
        );

        $this->assertStringContainsString('Reset Your Password', $html);
        $this->assertStringContainsString('Reset Password', $html);
        $this->assertStringContainsString('https://app.stratflow.com/reset-password?token=xyz789', $html);
    }

    #[Test]
    public function testSendConsultancyAlertBuildsHtml(): void
    {
        $service = $this->makeTestableService();

        $html = $service->buildHtml(
            'Consultancy Plan Activated',
            '<p>A new consultancy subscription has been created for <strong>Acme Corp</strong>.</p>',
            '',
            '',
            'This is an automated notification from StratFlow.'
        );

        $this->assertStringContainsString('Consultancy Plan Activated', $html);
        $this->assertStringContainsString('Acme Corp', $html);
        $this->assertStringContainsString('automated notification', $html);
        // No button block when buttonText and buttonUrl are empty strings
        $this->assertStringNotContainsString('<a href=""', $html);
    }
}
