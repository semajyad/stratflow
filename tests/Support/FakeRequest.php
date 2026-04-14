<?php

declare(strict_types=1);

namespace StratFlow\Tests\Support;

use StratFlow\Core\Request;

/**
 * FakeRequest
 *
 * Test double for Request. Reads from provided arrays instead of superglobals.
 * Allows controller tests to simulate any HTTP scenario without touching $_POST.
 */
final class FakeRequest extends Request
{
    public function __construct(
        private string $fakeMethod = 'GET',
        private string $fakeUri = '/',
        private array  $fakePost = [],
        private array  $fakeGet = [],
        private string $fakeIp = '127.0.0.1',
        private array  $fakeHeaders = [],
        private string $fakeBody = '',
    ) {}

    public function method(): string { return strtoupper($this->fakeMethod); }
    public function uri(): string    { return $this->fakeUri; }
    public function ip(): string     { return $this->fakeIp; }
    public function isPost(): bool   { return $this->fakeMethod === 'POST'; }
    public function isAjax(): bool   { return ($this->fakeHeaders['X-Requested-With'] ?? '') === 'XMLHttpRequest'; }
    public function body(): string   { return $this->fakeBody; }

    public function post(string $key, mixed $default = null): mixed
    {
        return $this->fakePost[$key] ?? $default;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->fakeGet[$key] ?? $default;
    }

    public function header(string $name): string
    {
        return $this->fakeHeaders[$name] ?? '';
    }

    public function json(): array
    {
        if ($this->fakeBody === '') {
            return [];
        }
        $decoded = json_decode($this->fakeBody, true);
        return is_array($decoded) ? $decoded : [];
    }
}
