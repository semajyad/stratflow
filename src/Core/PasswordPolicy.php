<?php

/**
 * Password Policy Enforcement
 *
 * Validates passwords against enterprise security requirements.
 * Meets PCI-DSS (min 7 chars + alphanumeric) and exceeds with 12-char
 * minimum plus special characters for HIPAA and SOC 2 compliance.
 *
 * Usage:
 *   $errors = PasswordPolicy::validate($password);
 *   if (!empty($errors)) { // show errors }
 */

declare(strict_types=1);

namespace StratFlow\Core;

class PasswordPolicy
{
    public const MIN_LENGTH = 12;
    public const REQUIRE_UPPERCASE = true;
    public const REQUIRE_LOWERCASE = true;
    public const REQUIRE_NUMBER = true;
    public const REQUIRE_SPECIAL = true;
/**
     * Validate a password against the policy and return any violations.
     *
     * @param string $password The password to validate
     * @return array           Array of human-readable error strings (empty if valid)
     */
    public static function validate(string $password): array
    {
        $errors = [];
        if (strlen($password) < self::MIN_LENGTH) {
            $errors[] = 'Password must be at least ' . self::MIN_LENGTH . ' characters.';
        }

        if (self::REQUIRE_UPPERCASE && !preg_match('/[A-Z]/', $password)) {
            $errors[] = 'Password must contain at least one uppercase letter.';
        }

        if (self::REQUIRE_LOWERCASE && !preg_match('/[a-z]/', $password)) {
            $errors[] = 'Password must contain at least one lowercase letter.';
        }

        if (self::REQUIRE_NUMBER && !preg_match('/[0-9]/', $password)) {
            $errors[] = 'Password must contain at least one number.';
        }

        if (self::REQUIRE_SPECIAL && !preg_match('/[^A-Za-z0-9]/', $password)) {
            $errors[] = 'Password must contain at least one special character.';
        }

        return $errors;
    }

    /**
     * Check whether a password meets all policy requirements.
     *
     * @param string $password The password to check
     * @return bool            True if the password is valid
     */
    public static function isValid(string $password): bool
    {
        return empty(self::validate($password));
    }

    /**
     * Return a human-readable summary of the password requirements.
     *
     * @return string Requirements description for UI display
     */
    public static function requirements(): string
    {
        return 'Minimum ' . self::MIN_LENGTH . ' characters with uppercase, lowercase, number, and special character.';
    }
}
