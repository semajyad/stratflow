<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\PasswordPolicy;

/**
 * PasswordPolicyTest
 *
 * Tests password policy enforcement for enterprise security requirements.
 * Validates minimum length, uppercase, lowercase, number, and special character requirements.
 */
class PasswordPolicyTest extends TestCase
{
    // ===========================
    // validate
    // ===========================

    #[Test]
    public function testValidateRejectsPasswordTooShort(): void
    {
        $errors = PasswordPolicy::validate('Short1!');

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('at least', $errors[0]);
        $this->assertStringContainsString('12', $errors[0]);
    }

    #[Test]
    public function testValidateRejectsMissingUppercase(): void
    {
        $errors = PasswordPolicy::validate('validpassword123!');

        $this->assertNotEmpty($errors);
        $this->assertTrue(
            in_array(true, array_map(fn($e) => str_contains($e, 'uppercase'), $errors)),
            'Should require uppercase letter'
        );
    }

    #[Test]
    public function testValidateRejectsMissingLowercase(): void
    {
        $errors = PasswordPolicy::validate('VALIDPASSWORD123!');

        $this->assertNotEmpty($errors);
        $this->assertTrue(
            in_array(true, array_map(fn($e) => str_contains($e, 'lowercase'), $errors)),
            'Should require lowercase letter'
        );
    }

    #[Test]
    public function testValidateRejectsMissingNumber(): void
    {
        $errors = PasswordPolicy::validate('ValidPassword!');

        $this->assertNotEmpty($errors);
        $this->assertTrue(
            in_array(true, array_map(fn($e) => str_contains($e, 'number'), $errors)),
            'Should require number'
        );
    }

    #[Test]
    public function testValidateRejectsMissingSpecialCharacter(): void
    {
        $errors = PasswordPolicy::validate('ValidPassword123');

        $this->assertNotEmpty($errors);
        $this->assertTrue(
            in_array(true, array_map(fn($e) => str_contains($e, 'special'), $errors)),
            'Should require special character'
        );
    }

    #[Test]
    public function testValidateAcceptsValidPassword(): void
    {
        $errors = PasswordPolicy::validate('ValidPassword123!');

        $this->assertEmpty($errors);
    }

    #[Test]
    public function testValidateAcceptsValidPasswordWithVariousSpecialChars(): void
    {
        $validPasswords = [
            'ValidPass123@',
            'ValidPass123#',
            'ValidPass123$',
            'ValidPass123%',
            'ValidPass123^',
            'ValidPass123&',
            'ValidPass123*',
            'ValidPass123-',
            'ValidPass123_',
        ];

        foreach ($validPasswords as $password) {
            $errors = PasswordPolicy::validate($password);
            $this->assertEmpty($errors, "Password '$password' should be valid");
        }
    }

    #[Test]
    public function testValidateReturnsMultipleErrorsWhenApplicable(): void
    {
        $errors = PasswordPolicy::validate('short');

        // Should contain multiple errors (too short + missing uppercase + missing number + missing special)
        $this->assertGreaterThan(1, count($errors));
    }

    // ===========================
    // isValid
    // ===========================

    #[Test]
    public function testIsValidReturnsTrueForValidPassword(): void
    {
        $result = PasswordPolicy::isValid('ValidPassword123!');

        $this->assertTrue($result);
    }

    #[Test]
    public function testIsValidReturnsFalseForInvalidPassword(): void
    {
        $result = PasswordPolicy::isValid('short');

        $this->assertFalse($result);
    }

    #[Test]
    public function testIsValidReturnsFalseWhenMissingUppercase(): void
    {
        $result = PasswordPolicy::isValid('validpassword123!');

        $this->assertFalse($result);
    }

    #[Test]
    public function testIsValidReturnsFalseWhenMissingLowercase(): void
    {
        $result = PasswordPolicy::isValid('VALIDPASSWORD123!');

        $this->assertFalse($result);
    }

    #[Test]
    public function testIsValidReturnsFalseWhenMissingNumber(): void
    {
        $result = PasswordPolicy::isValid('ValidPassword!');

        $this->assertFalse($result);
    }

    #[Test]
    public function testIsValidReturnsFalseWhenMissingSpecialChar(): void
    {
        $result = PasswordPolicy::isValid('ValidPassword123');

        $this->assertFalse($result);
    }

    // ===========================
    // requirements
    // ===========================

    #[Test]
    public function testRequirementsReturnsStringWithMinLength(): void
    {
        $req = PasswordPolicy::requirements();

        $this->assertIsString($req);
        $this->assertStringContainsString('12', $req);
    }

    #[Test]
    public function testRequirementsIncludesAllRequirements(): void
    {
        $req = PasswordPolicy::requirements();

        $this->assertStringContainsString('uppercase', $req);
        $this->assertStringContainsString('lowercase', $req);
        $this->assertStringContainsString('number', $req);
        $this->assertStringContainsString('special', $req);
    }

    #[Test]
    public function testRequirementsIsHumanReadable(): void
    {
        $req = PasswordPolicy::requirements();

        // Should be suitable for UI display
        $this->assertGreaterThan(20, strlen($req));
        $this->assertStringStartsWith('Minimum', $req);
    }
}
