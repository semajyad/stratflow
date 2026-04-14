<?php

declare(strict_types=1);

namespace StratFlow\Tests\Support;

use PHPUnit\Framework\TestCase;
use StratFlow\Core\Auth;
use StratFlow\Core\Database;

/**
 * ApiTestCase
 *
 * Base class for API controller tests. Provides:
 *  - FakeRequest / FakeResponse doubles with JSON body support
 *  - Mocked Auth pre-configured as a PAT-authenticated user
 *  - assertJsonShape() — lightweight structural schema assertions
 *    (validates required keys + types without an external library)
 *
 * Usage:
 *   class MyApiTest extends ApiTestCase
 *   {
 *       public function testMe(): void
 *       {
 *           $ctrl = new ApiStoriesController(
 *               $this->makeJsonGetRequest(),
 *               $this->response,
 *               $this->auth,
 *               $this->db,
 *               []
 *           );
 *           $ctrl->me();
 *           $this->assertJsonShape($this->response->jsonPayload, [
 *               'id'     => 'integer',
 *               'email'  => 'string',
 *               'org_id' => 'integer',
 *           ]);
 *       }
 *   }
 */
abstract class ApiTestCase extends TestCase
{
    protected FakeResponse $response;
    protected Auth         $auth;
    protected Database     $db;

    /** Default PAT-authenticated user injected into auth mock */
    protected array $apiUser = [
        'id'                => 42,
        'org_id'            => 5,
        'full_name'         => 'API Test User',
        'email'             => 'api@test.invalid',
        'role'              => 'user',
        'team'              => 'backend',
        'is_active'         => 1,
        'has_billing_access' => 0,
    ];

    /** Current stmt returned by every $this->db->query() call. Override per-test via makeDbStmt(). */
    protected \PDOStatement $currentDbStmt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->response = new FakeResponse();
        $this->db       = $this->createMock(Database::class);
        $this->auth     = $this->createMock(Auth::class);

        $this->auth->method('check')->willReturn(true);
        $this->auth->method('user')->willReturn($this->apiUser);
        $this->auth->method('orgId')->willReturn($this->apiUser['org_id']);

        // Default: empty results. Tests override $this->currentDbStmt via makeDbStmt().
        $this->currentDbStmt = $this->makeDbStmt();
        $this->db->method('query')->willReturnCallback(fn () => $this->currentDbStmt);
        $this->db->method('tableExists')->willReturn(true);
    }

    /**
     * Create a PDOStatement mock with the given return values.
     *
     * Assign the result to $this->currentDbStmt to make all subsequent
     * $this->db->query() calls return it.
     *
     * @param mixed $fetch     Return value for fetch() — false means "no row"
     * @param array $fetchAll  Return value for fetchAll()
     */
    protected function makeDbStmt(mixed $fetch = false, array $fetchAll = []): \PDOStatement
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn($fetch);
        $stmt->method('fetchAll')->willReturn($fetchAll);
        return $stmt;
    }

    // ===========================
    // Request helpers
    // ===========================

    protected function makeJsonGetRequest(array $query = [], string $uri = '/api/v1/test'): FakeRequest
    {
        return new FakeRequest('GET', $uri, [], $query, '127.0.0.1', [
            'Authorization' => 'Bearer test-pat-token',
            'Accept'        => 'application/json',
        ]);
    }

    protected function makeJsonPostRequest(array $body = [], string $uri = '/api/v1/test'): FakeRequest
    {
        return new FakeRequest(
            'POST',
            $uri,
            [],
            [],
            '127.0.0.1',
            [
                'Authorization' => 'Bearer test-pat-token',
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
            json_encode($body)
        );
    }

    // ===========================
    // Schema assertions
    // ===========================

    /**
     * Assert that $payload contains all keys in $schema with matching types.
     *
     * Schema format: ['key' => 'type'] where type is one of:
     *   string, integer, float, boolean, array, null, ?string, ?integer, ?array
     *
     * @param array  $payload  The actual JSON payload (decoded array)
     * @param array  $schema   Required key → type map
     * @param string $context  Prefix for assertion messages
     */
    protected function assertJsonShape(array $payload, array $schema, string $context = 'response'): void
    {
        foreach ($schema as $key => $type) {
            $nullable = str_starts_with($type, '?');
            $baseType = ltrim($type, '?');

            $this->assertArrayHasKey($key, $payload, "{$context}.{$key} is missing");

            $value = $payload[$key];

            if ($nullable && $value === null) {
                continue;
            }

            match ($baseType) {
                'string'  => $this->assertIsString($value, "{$context}.{$key} must be string"),
                'integer' => $this->assertIsInt($value, "{$context}.{$key} must be integer"),
                'float'   => $this->assertIsFloat($value, "{$context}.{$key} must be float"),
                'boolean' => $this->assertIsBool($value, "{$context}.{$key} must be boolean"),
                'array'   => $this->assertIsArray($value, "{$context}.{$key} must be array"),
                default   => $this->fail("Unknown type '{$type}' for key '{$key}'"),
            };
        }
    }

    /**
     * Assert that every item in an array-payload matches the given schema.
     */
    protected function assertJsonArrayShape(array $items, array $itemSchema, string $context = 'item'): void
    {
        foreach ($items as $i => $item) {
            $this->assertIsArray($item, "{$context}[{$i}] must be an array");
            $this->assertJsonShape($item, $itemSchema, "{$context}[{$i}]");
        }
    }
}
