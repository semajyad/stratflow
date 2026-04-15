<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Controllers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use StratFlow\Controllers\ApiProjectsController;
use StratFlow\Tests\Support\ApiTestCase;

/**
 * ApiProjectsControllerTest
 *
 * Tests for ApiProjectsController::index() — verifies JSON shape, status codes,
 * and data transformations (id casting, visibility defaulting, count calculation).
 *
 * All database calls return mocked results — we test controller logic and
 * response formatting, not the query layer.
 */
#[CoversClass(ApiProjectsController::class)]
#[UsesClass(\StratFlow\Models\Project::class)]
#[UsesClass(\StratFlow\Security\PermissionService::class)]
class ApiProjectsControllerTest extends ApiTestCase
{
    // ===========================
    // HELPER
    // ===========================

    private function makeController(): ApiProjectsController
    {
        return new ApiProjectsController(
            $this->makeJsonGetRequest(uri: '/api/v1/projects'),
            $this->response,
            $this->auth,
            $this->db,
            []
        );
    }

    // ===========================
    // GET /api/v1/projects
    // ===========================

    public function testIndexReturnsStatus200(): void
    {
        $this->currentDbStmt = $this->makeDbStmt(fetchAll: []);
        $this->makeController()->index();

        $this->assertSame(200, $this->response->jsonStatus);
    }

    public function testIndexReturnsEmptyDataWhenNoProjects(): void
    {
        $this->currentDbStmt = $this->makeDbStmt(fetchAll: []);
        $this->makeController()->index();

        $this->assertArrayHasKey('data', $this->response->jsonPayload);
        $this->assertSame([], $this->response->jsonPayload['data']);
        $this->assertArrayHasKey('count', $this->response->jsonPayload);
        $this->assertSame(0, $this->response->jsonPayload['count']);
    }

    public function testIndexReturnsProjectsWithCorrectShape(): void
    {
        $projects = [
            [
                'id'         => '1',
                'name'       => 'Project Alpha',
                'visibility' => 'everyone',
                'created_at' => '2025-04-01 10:00:00',
            ],
            [
                'id'         => '2',
                'name'       => 'Project Beta',
                'visibility' => 'restricted',
                'created_at' => '2025-04-02 11:00:00',
            ],
        ];
        $this->currentDbStmt = $this->makeDbStmt(fetchAll: $projects);
        $this->makeController()->index();

        $this->assertJsonShape($this->response->jsonPayload, [
            'data'  => 'array',
            'count' => 'integer',
        ]);
        $this->assertJsonArrayShape($this->response->jsonPayload['data'], [
            'id'         => 'integer',
            'name'       => 'string',
            'visibility' => 'string',
            'created_at' => 'string',
        ]);
    }

    public function testIndexCastsIdToInt(): void
    {
        $projects = [
            [
                'id'         => '5',
                'name'       => 'Test Project',
                'visibility' => 'everyone',
                'created_at' => '2025-04-01 10:00:00',
            ],
        ];
        $this->currentDbStmt = $this->makeDbStmt(fetchAll: $projects);
        $this->makeController()->index();

        $this->assertIsInt($this->response->jsonPayload['data'][0]['id']);
        $this->assertSame(5, $this->response->jsonPayload['data'][0]['id']);
    }

    public function testIndexDefaultsVisibilityToEveryone(): void
    {
        $projects = [
            [
                'id'         => '1',
                'name'       => 'Project Without Visibility',
                'created_at' => '2025-04-01 10:00:00',
                // Note: no 'visibility' key
            ],
        ];
        $this->currentDbStmt = $this->makeDbStmt(fetchAll: $projects);
        $this->makeController()->index();

        $this->assertSame('everyone', $this->response->jsonPayload['data'][0]['visibility']);
    }

    public function testIndexReturnsCorrectCount(): void
    {
        $projects = [
            [
                'id'         => '1',
                'name'       => 'Project 1',
                'visibility' => 'everyone',
                'created_at' => '2025-04-01 10:00:00',
            ],
            [
                'id'         => '2',
                'name'       => 'Project 2',
                'visibility' => 'everyone',
                'created_at' => '2025-04-02 11:00:00',
            ],
            [
                'id'         => '3',
                'name'       => 'Project 3',
                'visibility' => 'restricted',
                'created_at' => '2025-04-03 12:00:00',
            ],
        ];
        $this->currentDbStmt = $this->makeDbStmt(fetchAll: $projects);
        $this->makeController()->index();

        $this->assertSame(3, $this->response->jsonPayload['count']);
        $this->assertCount(3, $this->response->jsonPayload['data']);
    }
}
