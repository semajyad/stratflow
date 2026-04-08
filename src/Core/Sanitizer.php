<?php
/**
 * Input Sanitization Layer
 *
 * Provides consistent sanitization methods for all user input.
 * Defence-in-depth against XSS and injection attacks (OWASP A03).
 *
 * Usage:
 *   $name  = Sanitizer::string($_POST['name']);
 *   $email = Sanitizer::email($_POST['email']);
 *   $id    = Sanitizer::int($_POST['id']);
 *   $safe  = Sanitizer::html($untrustedInput);
 */

declare(strict_types=1);

namespace StratFlow\Core;

class Sanitizer
{
    /**
     * Sanitize a string by trimming whitespace and stripping HTML tags.
     *
     * @param mixed $input Raw input value
     * @return string      Cleaned string
     */
    public static function string(mixed $input): string
    {
        if (!is_string($input)) {
            return '';
        }
        return trim(strip_tags($input));
    }

    /**
     * Sanitize an email address.
     *
     * @param string $input Raw email input
     * @return string       Sanitized email or empty string if invalid
     */
    public static function email(string $input): string
    {
        return filter_var(trim($input), FILTER_SANITIZE_EMAIL) ?: '';
    }

    /**
     * Sanitize input to an integer value.
     *
     * @param mixed $input Raw input value
     * @return int         Integer value
     */
    public static function int(mixed $input): int
    {
        return (int) filter_var($input, FILTER_SANITIZE_NUMBER_INT);
    }

    /**
     * Escape a string for safe HTML output.
     *
     * Converts special characters to HTML entities to prevent XSS.
     *
     * @param string $input Raw string to escape
     * @return string       HTML-safe string
     */
    public static function html(string $input): string
    {
        return htmlspecialchars($input, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
