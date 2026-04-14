<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use StratFlow\Services\Logger;

/**
 * LoggerTest
 *
 * Tests the structured request logger: init(), set* methods, and that each
 * log-level method produces a valid JSON-line entry on stdout.
 *
 * Output is captured with output buffering since Logger writes to stdout.
 */
class LoggerTest extends TestCase
{
    /** @var resource */
    private mixed $stream;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stream = fopen('php://memory', 'r+');
        Logger::setOutputStream($this->stream);
        Logger::init();
    }

    protected function tearDown(): void
    {
        Logger::setOutputStream(null);
        fclose($this->stream);
        parent::tearDown();
    }

    private function captureLogLine(callable $fn): array
    {
        $fn();
        rewind($this->stream);
        $out = stream_get_contents($this->stream);
        // Reset stream for next capture
        ftruncate($this->stream, 0);
        rewind($this->stream);
        $this->assertNotEmpty($out, 'Logger produced no output');
        $decoded = json_decode(trim($out), true);
        $this->assertIsArray($decoded, 'Logger output is not valid JSON');
        return $decoded;
    }

    // ===========================
    // init / getReqId
    // ===========================

    public function testInitSetsNonEmptyReqId(): void
    {
        Logger::init();
        $this->assertNotEmpty(Logger::getReqId());
    }

    public function testInitGeneratesNewReqIdEachCall(): void
    {
        Logger::init();
        $id1 = Logger::getReqId();
        Logger::init();
        $id2 = Logger::getReqId();
        $this->assertNotSame($id1, $id2);
    }

    // ===========================
    // set* methods
    // ===========================

    public function testSetOrgAndUserAppearInLogOutput(): void
    {
        Logger::setOrg(42);
        Logger::setUser(7);
        Logger::setRoute('/app/home');

        $entry = $this->captureLogLine(fn() => Logger::info('test'));

        $this->assertSame(42, $entry['org_id']);
        $this->assertSame(7, $entry['user_id']);
        $this->assertSame('/app/home', $entry['route']);
    }

    // ===========================
    // log levels
    // ===========================

    public function testInfoWritesInfoLevel(): void
    {
        $entry = $this->captureLogLine(fn() => Logger::info('hello info'));
        $this->assertSame('info', $entry['level']);
        $this->assertSame('hello info', $entry['msg']);
    }

    public function testDebugWritesDebugLevel(): void
    {
        $entry = $this->captureLogLine(fn() => Logger::debug('debug msg'));
        $this->assertSame('debug', $entry['level']);
    }

    public function testWarnWritesWarnLevel(): void
    {
        $entry = $this->captureLogLine(fn() => Logger::warn('warning here'));
        $this->assertSame('warn', $entry['level']);
    }

    public function testErrorWritesErrorLevel(): void
    {
        $entry = $this->captureLogLine(fn() => Logger::error('something broke'));
        $this->assertSame('error', $entry['level']);
    }

    // ===========================
    // JSON structure
    // ===========================

    public function testLogEntryContainsRequiredFields(): void
    {
        $entry = $this->captureLogLine(fn() => Logger::info('structure test'));
        $this->assertArrayHasKey('ts', $entry);
        $this->assertArrayHasKey('level', $entry);
        $this->assertArrayHasKey('req_id', $entry);
        $this->assertArrayHasKey('msg', $entry);
    }

    public function testContextIsIncludedInOutput(): void
    {
        $entry = $this->captureLogLine(fn() => Logger::info('ctx test', ['key' => 'value']));
        $this->assertSame('value', $entry['ctx']['key'] ?? $entry['key'] ?? null);
    }

    public function testLogConstants(): void
    {
        $this->assertSame('debug', Logger::DEBUG);
        $this->assertSame('info',  Logger::INFO);
        $this->assertSame('warn',  Logger::WARN);
        $this->assertSame('error', Logger::ERROR);
    }
}
