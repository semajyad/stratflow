<?php

/**
 * Structured Request Logger
 *
 * Writes JSON-line log entries to stdout so Railway (and any other container
 * platform) can capture them via its log aggregator.
 *
 * Fields: ts, level, req_id, org_id, user_id, route, status, ms, msg, ctx
 *
 * Usage:
 *   Logger::info('User logged in', ['email_hash' => sha1($email)]);
 *   Logger::error('DB query failed', ['error' => $e->getMessage()]);
 */

declare(strict_types=1);

namespace StratFlow\Services;

class Logger
{
    // ===========================
    // LEVEL CONSTANTS
    // ===========================

    public const DEBUG = 'debug';
    public const INFO  = 'info';
    public const WARN  = 'warn';
    public const ERROR = 'error';

    // ===========================
    // REQUEST-SCOPED STATE
    // ===========================

    private static string $reqId  = '';
    private static ?int $orgId  = null;
    private static ?int $userId = null;
    private static string $route  = '';
    private static float $startedAt = 0.0;

    /** @var resource|null Override stream for testing (null = STDOUT) */
    private static mixed $outputStream = null;

    /**
     * Override the output stream (for testing only).
     *
     * @param resource|null $stream A writable stream, or null to restore STDOUT
     */
    public static function setOutputStream(mixed $stream): void
    {
        self::$outputStream = $stream;
    }

    // ===========================
    // LIFECYCLE
    // ===========================

    /**
     * Call once at the top of the request lifecycle.
     * Generates a req_id and captures the request start time.
     */
    public static function init(): void
    {
        self::$reqId     = bin2hex(random_bytes(8));
        self::$startedAt = microtime(true);
    }

    public static function setOrg(int $orgId): void
    {
        self::$orgId = $orgId;
    }

    public static function setUser(int $userId): void
    {
        self::$userId = $userId;
    }

    public static function setRoute(string $route): void
    {
        self::$route = $route;
    }

    public static function getReqId(): string
    {
        return self::$reqId;
    }

    // ===========================
    // LOG METHODS
    // ===========================

    public static function debug(string $msg, array $ctx = []): void
    {
        self::write(self::DEBUG, $msg, $ctx);
    }

    public static function info(string $msg, array $ctx = []): void
    {
        self::write(self::INFO, $msg, $ctx);
    }

    public static function warn(string $msg, array $ctx = []): void
    {
        self::write(self::WARN, $msg, $ctx);
    }

    public static function error(string $msg, array $ctx = []): void
    {
        self::write(self::ERROR, $msg, $ctx);
    }

    /**
     * Emit a final request-summary log line (status + elapsed ms).
     */
    public static function request(int $status): void
    {
        $ms = self::$startedAt > 0.0
            ? (int) round((microtime(true) - self::$startedAt) * 1000)
            : null;

        self::write(self::INFO, 'request', ['status' => $status, 'ms' => $ms]);
    }

    // ===========================
    // INTERNAL
    // ===========================

    private static function write(string $level, string $msg, array $ctx): void
    {
        $entry = [
            'ts'      => date('c'),
            'level'   => $level,
            'req_id'  => self::$reqId,
            'org_id'  => self::$orgId,
            'user_id' => self::$userId,
            'route'   => self::$route,
            'msg'     => $msg,
        ];

        if (!empty($ctx)) {
            $entry['ctx'] = $ctx;
        }

        // Write to stdout — container platform captures this.
        // STDOUT constant is unavailable in php-fpm and the built-in web
        // server's forked request workers, so we open php://stdout which
        // is always available regardless of SAPI.
        static $stdoutHandle = null;
        if ($stdoutHandle === null) {
            $stdoutHandle = fopen('php://stdout', 'wb');
        }
        $stream = self::$outputStream ?? $stdoutHandle;
        if ($stream !== false && $stream !== null) {
            fwrite($stream, json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        } else {
            error_log(json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
    }
}
