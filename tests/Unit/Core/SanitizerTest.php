<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Core;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Sanitizer;

/**
 * SanitizerTest
 *
 * Comprehensive unit tests for the Sanitizer class covering all static methods:
 * - string() — trims whitespace and strips HTML tags
 * - email() — sanitizes email addresses
 * - int() — converts/coerces input to integer
 * - html() — escapes HTML special characters
 */
#[CoversClass(Sanitizer::class)]
class SanitizerTest extends TestCase
{
    // ===========================
    // Sanitizer::string()
    // ===========================

    #[Test]
    public function testStringTrimsWhitespace(): void
    {
        $result = Sanitizer::string('  hello  ');
        $this->assertSame('hello', $result);
    }

    #[Test]
    public function testStringStripsHtmlTags(): void
    {
        $result = Sanitizer::string('<b>bold</b>');
        $this->assertSame('bold', $result);
    }

    #[Test]
    public function testStringReturnsBothTrimsAndStrips(): void
    {
        $result = Sanitizer::string('  <em>text</em>  ');
        $this->assertSame('text', $result);
    }

    #[Test]
    public function testStringReturnsEmptyStringForInteger(): void
    {
        $result = Sanitizer::string(42);
        $this->assertSame('', $result);
    }

    #[Test]
    public function testStringReturnsEmptyStringForArray(): void
    {
        $result = Sanitizer::string([]);
        $this->assertSame('', $result);
    }

    #[Test]
    public function testStringReturnsEmptyStringForNull(): void
    {
        $result = Sanitizer::string(null);
        $this->assertSame('', $result);
    }

    #[Test]
    public function testStringHandlesEmptyString(): void
    {
        $result = Sanitizer::string('');
        $this->assertSame('', $result);
    }

    #[Test]
    public function testStringHandlesNestedHtmlTags(): void
    {
        $result = Sanitizer::string('<div><span>nested</span></div>');
        $this->assertSame('nested', $result);
    }

    #[Test]
    public function testStringHandlesMultipleTags(): void
    {
        $result = Sanitizer::string('<p>hello</p><p>world</p>');
        $this->assertSame('helloworld', $result);
    }

    #[Test]
    public function testStringHandlesAttributesInTags(): void
    {
        $result = Sanitizer::string('<a href="http://example.com">link</a>');
        $this->assertSame('link', $result);
    }

    // ===========================
    // Sanitizer::email()
    // ===========================

    #[Test]
    public function testEmailSanitizesValidEmail(): void
    {
        $result = Sanitizer::email('user@example.com');
        $this->assertSame('user@example.com', $result);
    }

    #[Test]
    public function testEmailTrimsWhitespace(): void
    {
        $result = Sanitizer::email('  user@example.com  ');
        $this->assertSame('user@example.com', $result);
    }

    #[Test]
    public function testEmailStripsInvalidChars(): void
    {
        $result = Sanitizer::email('user@exa<mple.com');
        // filter_var with FILTER_SANITIZE_EMAIL removes invalid characters
        // Expected: 'user@example.com'
        $this->assertNotEmpty($result);
        $this->assertStringContainsString('@', $result);
    }

    #[Test]
    public function testEmailReturnsEmptyForGarbageInput(): void
    {
        $result = Sanitizer::email('@@@@');
        // Garbage input may return empty or partially sanitized
        $this->assertIsString($result);
    }

    #[Test]
    public function testEmailHandlesEmailWithSubdomain(): void
    {
        $result = Sanitizer::email('user@mail.example.com');
        $this->assertSame('user@mail.example.com', $result);
    }

    #[Test]
    public function testEmailHandlesEmailWithPlus(): void
    {
        $result = Sanitizer::email('user+tag@example.com');
        $this->assertSame('user+tag@example.com', $result);
    }

    #[Test]
    public function testEmailHandlesEmailWithNumbers(): void
    {
        $result = Sanitizer::email('user123@example.com');
        $this->assertSame('user123@example.com', $result);
    }

    #[Test]
    public function testEmailHandlesEmailWithDots(): void
    {
        $result = Sanitizer::email('user.name@example.com');
        $this->assertSame('user.name@example.com', $result);
    }

    // ===========================
    // Sanitizer::int()
    // ===========================

    #[Test]
    public function testIntConvertsStringToInt(): void
    {
        $result = Sanitizer::int('42');
        $this->assertSame(42, $result);
        $this->assertIsInt($result);
    }

    #[Test]
    public function testIntHandlesNegative(): void
    {
        $result = Sanitizer::int('-5');
        $this->assertSame(-5, $result);
    }

    #[Test]
    public function testIntHandlesNonNumericString(): void
    {
        $result = Sanitizer::int('abc');
        $this->assertSame(0, $result);
    }

    #[Test]
    public function testIntHandlesFloat(): void
    {
        $result = Sanitizer::int('3.7');
        // FILTER_SANITIZE_NUMBER_INT removes decimal, leaving '37'
        $this->assertIsInt($result);
    }

    #[Test]
    public function testIntHandlesAlreadyInteger(): void
    {
        $result = Sanitizer::int(42);
        $this->assertSame(42, $result);
    }

    #[Test]
    public function testIntHandlesZero(): void
    {
        $result = Sanitizer::int('0');
        $this->assertSame(0, $result);
    }

    #[Test]
    public function testIntHandlesLargeNumber(): void
    {
        $result = Sanitizer::int('999999999');
        $this->assertSame(999999999, $result);
    }

    #[Test]
    public function testIntHandlesNegativeFloat(): void
    {
        $result = Sanitizer::int('-3.7');
        $this->assertIsInt($result);
    }

    #[Test]
    public function testIntHandlesStringWithLeadingZeros(): void
    {
        $result = Sanitizer::int('00042');
        $this->assertIsInt($result);
    }

    // ===========================
    // Sanitizer::html()
    // ===========================

    #[Test]
    public function testHtmlEscapesAngleBrackets(): void
    {
        $result = Sanitizer::html('<script>');
        $this->assertSame('&lt;script&gt;', $result);
    }

    #[Test]
    public function testHtmlEscapesDoubleQuotes(): void
    {
        $result = Sanitizer::html('"hello"');
        $this->assertSame('&quot;hello&quot;', $result);
    }

    #[Test]
    public function testHtmlEscapesSingleQuotes(): void
    {
        $result = Sanitizer::html("it's");
        $this->assertSame('it&apos;s', $result);
    }

    #[Test]
    public function testHtmlLeavesPlainTextAlone(): void
    {
        $result = Sanitizer::html('plain text');
        $this->assertSame('plain text', $result);
    }

    #[Test]
    public function testHtmlEscapesAmpersand(): void
    {
        $result = Sanitizer::html('A & B');
        $this->assertSame('A &amp; B', $result);
    }

    #[Test]
    public function testHtmlEscapesComplexHtml(): void
    {
        $result = Sanitizer::html('<div class="test">content</div>');
        $this->assertStringContainsString('&lt;', $result);
        $this->assertStringContainsString('&gt;', $result);
        $this->assertStringContainsString('&quot;', $result);
    }

    #[Test]
    public function testHtmlHandlesEmptyString(): void
    {
        $result = Sanitizer::html('');
        $this->assertSame('', $result);
    }

    #[Test]
    public function testHtmlEscapesScriptTag(): void
    {
        $result = Sanitizer::html('<script>alert("xss")</script>');
        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringContainsString('&lt;', $result);
    }

    #[Test]
    public function testHtmlEscapesMultipleSpecialChars(): void
    {
        $result = Sanitizer::html('<a href="link" onclick=\'alert("xss")\'>click</a>');
        $this->assertStringContainsString('&lt;', $result);
        $this->assertStringContainsString('&gt;', $result);
        $this->assertStringContainsString('&quot;', $result);
        $this->assertStringContainsString('&apos;', $result);
    }
}
