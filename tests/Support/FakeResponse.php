<?php

declare(strict_types=1);

namespace StratFlow\Tests\Support;

use StratFlow\Core\CSRF;
use StratFlow\Core\Response;

/**
 * FakeResponse
 *
 * Test double for Response. Captures render/redirect/json calls instead of
 * sending HTTP headers or including template files.
 */
final class FakeResponse extends Response
{
    public ?string $renderedTemplate = null;
    public array   $renderedData     = [];
    public ?array  $jsonPayload      = null;
    public int     $jsonStatus       = 200;
    public ?string $redirectedTo     = null;

    public function __construct()
    {
        // Skip parent constructor (needs CSRF) — not needed for fakes
    }

    public function render(string $template, array $data = [], string $layout = 'public'): void
    {
        $this->renderedTemplate = $template;
        $this->renderedData     = $data;
    }

    public function json(array $data, int $status = 200): void
    {
        $this->jsonPayload = $data;
        $this->jsonStatus  = $status;
    }

    public function redirect(string $url): void
    {
        $this->redirectedTo = $url;
    }
}
