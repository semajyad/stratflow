<?php
/**
 * EmailService
 *
 * Sends transactional emails via Resend API (primary) with Gmail SMTP fallback.
 * Resend: simple HTTP POST, high deliverability, no PHP extensions needed.
 * Gmail SMTP: fallback via fsockopen with STARTTLS.
 */

declare(strict_types=1);

namespace StratFlow\Services;

class EmailService
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    // =========================================================================
    // CORE SEND METHOD
    // =========================================================================

    /**
     * Send an HTML email. Tries Resend API first, falls back to Gmail SMTP.
     */
    public function send(string $to, string $subject, string $htmlBody): bool
    {
        $fromName = $this->config['mail']['from_name'] ?? 'StratFlow';
        $fromEmail = $this->config['mail']['from_email'] ?? 'noreply@stratflow.app';
        $resendKey = $this->config['mail']['resend_api_key'] ?? '';

        // Primary: Resend API
        if ($resendKey !== '') {
            try {
                $sent = $this->sendViaResend($resendKey, $fromName, $fromEmail, $to, $subject, $htmlBody);
                if ($sent) {
                    error_log("[StratFlow] Email sent via Resend to {$to}");
                    return true;
                }
            } catch (\Throwable $e) {
                error_log("[StratFlow] Resend failed: {$e->getMessage()}, falling back to SMTP");
            }
        }

        // Fallback: Gmail SMTP
        $smtpUser = $this->config['mail']['smtp_user'] ?? '';
        $smtpPass = $this->config['mail']['smtp_pass'] ?? '';

        if ($smtpUser !== '' && $smtpPass !== '') {
            try {
                $smtpHost = $this->config['mail']['smtp_host'] ?? 'smtp.gmail.com';
                $smtpPort = (int)($this->config['mail']['smtp_port'] ?? 587);
                $smtpFrom = $this->config['mail']['smtp_user'];

                $headers = "From: {$fromName} <{$smtpFrom}>\r\n";
                $headers .= "To: {$to}\r\n";
                $headers .= "Subject: {$subject}\r\n";
                $headers .= "MIME-Version: 1.0\r\n";
                $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
                $message = $headers . "\r\n" . $htmlBody;

                $sent = $this->sendViaSMTP($smtpHost, $smtpPort, $smtpUser, $smtpPass, $smtpFrom, $to, $message);
                if ($sent) {
                    error_log("[StratFlow] Email sent via SMTP to {$to}");
                    return true;
                }
            } catch (\Throwable $e) {
                error_log("[StratFlow] SMTP also failed: {$e->getMessage()}");
            }
        }

        error_log("[StratFlow] Email FAILED to {$to} — no delivery method succeeded");
        return false;
    }

    // =========================================================================
    // RESEND API
    // =========================================================================

    /**
     * Send email via Resend HTTP API.
     * https://resend.com/docs/api-reference/emails/send-email
     */
    private function sendViaResend(string $apiKey, string $fromName, string $fromEmail, string $to, string $subject, string $htmlBody): bool
    {
        $payload = json_encode([
            'from' => "{$fromName} <{$fromEmail}>",
            'to' => [$to],
            'subject' => $subject,
            'html' => $htmlBody,
        ]);

        $ch = curl_init('https://api.resend.com/emails');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new \RuntimeException("Resend cURL error: {$curlError}");
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            return true;
        }

        $body = json_decode($response, true);
        $errorMsg = $body['message'] ?? $body['error'] ?? "HTTP {$httpCode}";
        throw new \RuntimeException("Resend API error: {$errorMsg}");
    }

    // =========================================================================
    // GMAIL SMTP FALLBACK
    // =========================================================================

    private function sendViaSMTP(string $host, int $port, string $user, string $pass, string $from, string $to, string $message): bool
    {
        $socket = @fsockopen($host, $port, $errno, $errstr, 10);
        if (!$socket) {
            throw new \RuntimeException("SMTP connect failed: {$errstr} ({$errno})");
        }

        $this->smtpRead($socket);
        $this->smtpCommand($socket, "EHLO stratflow.app", 250);
        $this->smtpCommand($socket, "STARTTLS", 220);

        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
            throw new \RuntimeException("STARTTLS negotiation failed");
        }

        $this->smtpCommand($socket, "EHLO stratflow.app", 250);
        $this->smtpCommand($socket, "AUTH LOGIN", 334);
        $this->smtpCommand($socket, base64_encode($user), 334);
        $this->smtpCommand($socket, base64_encode($pass), 235);
        $this->smtpCommand($socket, "MAIL FROM:<{$from}>", 250);
        $this->smtpCommand($socket, "RCPT TO:<{$to}>", 250);
        $this->smtpCommand($socket, "DATA", 354);
        fwrite($socket, $message . "\r\n.\r\n");
        $this->smtpRead($socket);
        $this->smtpCommand($socket, "QUIT", 221);
        fclose($socket);

        return true;
    }

    private function smtpCommand($socket, string $command, int $expectedCode): string
    {
        fwrite($socket, $command . "\r\n");
        $response = $this->smtpRead($socket);
        $code = (int)substr($response, 0, 3);
        if ($code !== $expectedCode) {
            throw new \RuntimeException("SMTP error: expected {$expectedCode}, got: {$response}");
        }
        return $response;
    }

    private function smtpRead($socket): string
    {
        $response = '';
        while ($line = fgets($socket, 512)) {
            $response .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        return $response;
    }

    // =========================================================================
    // CONVENIENCE METHODS
    // =========================================================================

    public function sendWelcome(string $to, string $name, string $setPasswordUrl): bool
    {
        $subject = 'Welcome to StratFlow — Set Your Password';
        $htmlBody = $this->buildEmailHtml(
            "Welcome to StratFlow, {$name}!",
            '<p>Your account has been created. Click the button below to set your password and get started.</p>',
            'Set Your Password',
            $setPasswordUrl,
            'This link expires in 24 hours. If you didn\'t expect this email, you can safely ignore it.'
        );
        return $this->send($to, $subject, $htmlBody);
    }

    public function sendPasswordReset(string $to, string $name, string $resetUrl): bool
    {
        $subject = 'StratFlow — Reset Your Password';
        $htmlBody = $this->buildEmailHtml(
            "Reset Your Password",
            "<p>Hi {$name}, we received a request to reset your password. Click the button below to choose a new one.</p>",
            'Reset Password',
            $resetUrl,
            'This link expires in 24 hours. If you didn\'t request a password reset, you can safely ignore this email.'
        );
        return $this->send($to, $subject, $htmlBody);
    }

    public function sendConsultancyAlert(string $to, string $orgName): bool
    {
        $subject = 'StratFlow — New Consultancy Subscription';
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

    private function buildEmailHtml(string $heading, string $bodyHtml, string $buttonText, string $buttonUrl, string $footerText): string
    {
        $buttonBlock = '';
        if ($buttonText !== '' && $buttonUrl !== '') {
            $safeUrl = htmlspecialchars($buttonUrl, ENT_QUOTES, 'UTF-8');
            $safeText = htmlspecialchars($buttonText, ENT_QUOTES, 'UTF-8');
            $buttonBlock = <<<HTML
            <div style="text-align: center; margin: 32px 0;">
                <a href="{$safeUrl}" style="background: #4f46e5; color: white; padding: 12px 32px; text-decoration: none; border-radius: 6px; font-weight: 600; display: inline-block;">{$safeText}</a>
            </div>
            <p style="color: #94a3b8; font-size: 13px; word-break: break-all;">Or copy this link: {$safeUrl}</p>
            HTML;
        }

        $safeHeading = htmlspecialchars($heading, ENT_QUOTES, 'UTF-8');
        $safeFooter = htmlspecialchars($footerText, ENT_QUOTES, 'UTF-8');

        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head><meta charset="UTF-8"></head>
        <body style="margin: 0; padding: 0; background: #f1f5f9; font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">
            <div style="max-width: 560px; margin: 40px auto;">
                <div style="background: #4f46e5; padding: 24px; text-align: center; border-radius: 8px 8px 0 0;">
                    <h1 style="color: white; margin: 0; font-size: 24px; font-weight: 700;">StratFlow</h1>
                </div>
                <div style="padding: 32px; background: white; border: 1px solid #e2e8f0; border-top: none;">
                    <h2 style="margin: 0 0 16px 0; color: #0f172a; font-size: 20px;">{$safeHeading}</h2>
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
