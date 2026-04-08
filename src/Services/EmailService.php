<?php
/**
 * EmailService
 *
 * Sends transactional emails using PHP's built-in mail() function.
 * Provides convenience methods for welcome emails, password resets,
 * and consultancy alerts with StratFlow-branded HTML templates.
 */

declare(strict_types=1);

namespace StratFlow\Services;

class EmailService
{
    private array $config;

    /**
     * @param array $config Application config array (expects 'mail' key with from_name and from_email)
     */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    // =========================================================================
    // CORE SEND METHOD
    // =========================================================================

    /**
     * Send an HTML email via PHP's mail() function.
     *
     * @param string $to       Recipient email address
     * @param string $subject  Email subject line
     * @param string $htmlBody Full HTML body content
     * @return bool            True if mail() accepted the message for delivery
     */
    public function send(string $to, string $subject, string $htmlBody): bool
    {
        $fromName  = $this->config['mail']['from_name'] ?? 'StratFlow';
        $fromEmail = $this->config['mail']['from_email'] ?? 'noreply@stratflow.app';

        $headers = [
            'From'         => "{$fromName} <{$fromEmail}>",
            'Reply-To'     => $fromEmail,
            'MIME-Version'  => '1.0',
            'Content-Type' => 'text/html; charset=UTF-8',
            'X-Mailer'     => 'StratFlow/1.0',
        ];

        $headerStr = '';
        foreach ($headers as $key => $value) {
            $headerStr .= "{$key}: {$value}\r\n";
        }

        return mail($to, $subject, $htmlBody, $headerStr);
    }

    // =========================================================================
    // CONVENIENCE METHODS
    // =========================================================================

    /**
     * Send a welcome email with a "Set Your Password" link.
     *
     * @param string $to             Recipient email address
     * @param string $name           Recipient's display name
     * @param string $setPasswordUrl Full URL to the set-password page (includes token)
     * @return bool                  True if mail() accepted the message
     */
    public function sendWelcome(string $to, string $name, string $setPasswordUrl): bool
    {
        $subject  = 'Welcome to StratFlow — Set Your Password';
        $htmlBody = $this->buildEmailHtml(
            "Welcome to StratFlow, {$name}!",
            '<p>Your account has been created. Click the button below to set your password and get started.</p>',
            'Set Your Password',
            $setPasswordUrl,
            'This link expires in 24 hours. If you didn\'t expect this email, you can safely ignore it.'
        );

        return $this->send($to, $subject, $htmlBody);
    }

    /**
     * Send a password reset email with a reset link.
     *
     * @param string $to       Recipient email address
     * @param string $name     Recipient's display name
     * @param string $resetUrl Full URL to the password reset page (includes token)
     * @return bool            True if mail() accepted the message
     */
    public function sendPasswordReset(string $to, string $name, string $resetUrl): bool
    {
        $subject  = 'StratFlow — Reset Your Password';
        $htmlBody = $this->buildEmailHtml(
            "Reset Your Password",
            "<p>Hi {$name}, we received a request to reset your password. Click the button below to choose a new one.</p>",
            'Reset Password',
            $resetUrl,
            'This link expires in 24 hours. If you didn\'t request a password reset, you can safely ignore this email.'
        );

        return $this->send($to, $subject, $htmlBody);
    }

    /**
     * Send a consultancy plan alert email.
     *
     * @param string $to      Recipient email address
     * @param string $orgName Organisation name
     * @return bool           True if mail() accepted the message
     */
    public function sendConsultancyAlert(string $to, string $orgName): bool
    {
        $subject  = 'StratFlow — New Consultancy Subscription';
        $htmlBody = $this->buildEmailHtml(
            "Consultancy Plan Activated",
            "<p>A new consultancy subscription has been created for <strong>{$orgName}</strong>. "
            . "Please reach out to the customer to begin onboarding.</p>",
            '',
            '',
            'This is an automated notification from StratFlow.'
        );

        return $this->send($to, $subject, $htmlBody);
    }

    // =========================================================================
    // HTML BUILDER
    // =========================================================================

    /**
     * Build a branded HTML email body with inline CSS.
     *
     * @param string $heading    Main heading text
     * @param string $bodyHtml   HTML content for the body paragraph(s)
     * @param string $buttonText CTA button label (empty string to omit button)
     * @param string $buttonUrl  CTA button URL
     * @param string $footerText Small-print footer text
     * @return string            Complete HTML email body
     */
    private function buildEmailHtml(
        string $heading,
        string $bodyHtml,
        string $buttonText,
        string $buttonUrl,
        string $footerText
    ): string {
        $buttonBlock = '';
        if ($buttonText !== '' && $buttonUrl !== '') {
            $safeUrl = htmlspecialchars($buttonUrl, ENT_QUOTES, 'UTF-8');
            $safeText = htmlspecialchars($buttonText, ENT_QUOTES, 'UTF-8');
            $buttonBlock = <<<HTML
            <div style="text-align: center; margin: 32px 0;">
                <a href="{$safeUrl}" style="background: #2563eb; color: white; padding: 12px 32px; text-decoration: none; border-radius: 6px; font-weight: 600; display: inline-block;">{$safeText}</a>
            </div>
            <p style="color: #94a3b8; font-size: 13px; word-break: break-all;">Or copy this link: {$safeUrl}</p>
            HTML;
        }

        $safeHeading = htmlspecialchars($heading, ENT_QUOTES, 'UTF-8');
        $safeFooter  = htmlspecialchars($footerText, ENT_QUOTES, 'UTF-8');

        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head><meta charset="UTF-8"></head>
        <body style="margin: 0; padding: 0; background: #f1f5f9; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">
            <div style="max-width: 560px; margin: 40px auto; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">
                <div style="background: #2563eb; padding: 24px; text-align: center; border-radius: 8px 8px 0 0;">
                    <h1 style="color: white; margin: 0; font-size: 24px; font-weight: 700;">StratFlow</h1>
                </div>
                <div style="padding: 32px; background: white; border: 1px solid #e2e8f0; border-top: none;">
                    <h2 style="margin: 0 0 16px 0; color: #1e293b; font-size: 20px;">{$safeHeading}</h2>
                    {$bodyHtml}
                    {$buttonBlock}
                    <p style="color: #64748b; font-size: 14px; margin-top: 24px;">{$safeFooter}</p>
                </div>
                <div style="padding: 16px; text-align: center; color: #94a3b8; font-size: 12px;">
                    &copy; ThreePoints Solutions
                </div>
            </div>
        </body>
        </html>
        HTML;
    }
}
