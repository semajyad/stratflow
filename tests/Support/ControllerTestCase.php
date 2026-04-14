<?php

declare(strict_types=1);

namespace StratFlow\Tests\Support;

use PHPUnit\Framework\TestCase;
use StratFlow\Core\Auth;
use StratFlow\Core\Database;

/**
 * ControllerTestCase
 *
 * Base class for controller unit tests. Provides:
 *  - FakeRequest / FakeResponse doubles (no real headers or template I/O)
 *  - Mocked Auth and Database so controllers can be instantiated in isolation
 *  - Minimal config array matching what controllers expect
 *
 * Usage:
 *   class MyControllerTest extends ControllerTestCase
 *   {
 *       public function testHappyPath(): void
 *       {
 *           $request = $this->makePostRequest(['email' => 'x@y.com']);
 *           $ctrl = new MyController($request, $this->response, $this->auth, $this->db, $this->config);
 *           $ctrl->myAction();
 *           $this->assertSame('/app/home', $this->response->redirectedTo);
 *       }
 *   }
 */
abstract class ControllerTestCase extends TestCase
{
    protected FakeResponse $response;
    protected Auth         $auth;
    protected Database     $db;
    protected array        $config;

    protected function setUp(): void
    {
        parent::setUp();
        $this->response = new FakeResponse();
        $this->db       = $this->createMock(Database::class);
        $this->auth     = $this->createMock(Auth::class);
        $this->config   = $this->defaultConfig();
    }

    protected function makeGetRequest(array $query = [], string $uri = '/'): FakeRequest
    {
        return new FakeRequest('GET', $uri, [], $query);
    }

    protected function makePostRequest(array $body = [], string $uri = '/'): FakeRequest
    {
        return new FakeRequest('POST', $uri, $body);
    }

    protected function actingAs(array $user): void
    {
        $this->auth->method('check')->willReturn(true);
        $this->auth->method('user')->willReturn($user);
        $this->auth->method('orgId')->willReturn((int) ($user['org_id'] ?? 1));
    }

    protected function actingAsAdmin(int $orgId = 1): void
    {
        $this->actingAs([
            'id'       => 1,
            'org_id'   => $orgId,
            'role'     => 'admin',
            'email'    => 'admin@test.invalid',
            'is_active' => 1,
        ]);
    }

    private function defaultConfig(): array
    {
        return [
            'app'    => ['url' => 'http://localhost', 'debug' => false],
            'stripe' => [
                'secret_key'      => 'sk_test_xxx',
                'publishable_key' => 'pk_test_xxx',
                'webhook_secret'  => 'whsec_xxx',
                'price_product'   => 'price_xxx',
            ],
            'jira'   => [],
        ];
    }
}
