<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Controllers;

use StratFlow\Controllers\ApiStoriesController;
use StratFlow\Tests\Support\ApiTestCase;

/**
 * ApiStoriesControllerTest
 *
 * Tests API contract conformance for /api/v1/me, /api/v1/stories, and
 * /api/v1/stories/{id}/status. Validates response shape against the
 * OpenAPI schema definitions in docs/openapi.yaml.
 *
 * All DB calls return empty/mocked results — we're testing the controller
 * logic (JSON shape, status codes, validation) not the query layer.
 */
class ApiStoriesControllerTest extends ApiTestCase
{
    // ===========================
    // HELPER
    // ===========================

    private function makeController(): ApiStoriesController
    {
        return new ApiStoriesController(
            $this->makeJsonGetRequest(),
            $this->response,
            $this->auth,
            $this->db,
            []
        );
    }

    // ===========================
    // GET /api/v1/me
    // ===========================

    public function testMeReturns200WithUserShape(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(['team' => 'backend']);
        $this->db->method('query')->willReturn($stmt);

        $ctrl = $this->makeController();
        $ctrl->me();

        $this->assertSame(200, $this->response->jsonStatus);
        $this->assertJsonShape($this->response->jsonPayload, [
            'id'     => 'integer',
            'name'   => 'string',
            'email'  => 'string',
            'org_id' => 'integer',
            'team'   => '?string',
        ]);
    }

    public function testMeReturnsAuthenticatedUserId(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);
        $this->db->method('query')->willReturn($stmt);

        $this->makeController()->me();

        $this->assertSame($this->apiUser['id'], $this->response->jsonPayload['id']);
        $this->assertSame($this->apiUser['email'], $this->response->jsonPayload['email']);
        $this->assertSame($this->apiUser['org_id'], $this->response->jsonPayload['org_id']);
    }

    public function testMeReturnsNullTeamWhenNotSet(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);
        $this->db->method('query')->willReturn($stmt);

        $this->makeController()->me();

        $this->assertNull($this->response->jsonPayload['team']);
    }

    // ===========================
    // GET /api/v1/stories
    // ===========================

    public function testIndexReturns200WithStoriesArray(): void
    {
        $this->makeController()->index();

        $this->assertSame(200, $this->response->jsonStatus);
        $this->assertArrayHasKey('data', $this->response->jsonPayload);
        $this->assertIsArray($this->response->jsonPayload['data']);
    }

    public function testIndexReturnsEmptyStoriesWhenNoneExist(): void
    {
        $this->makeController()->index();

        $this->assertSame([], $this->response->jsonPayload['data']);
    }

    public function testIndexStoriesMatchSchema(): void
    {
        $storyRow = [
            'id'           => 1,
            'title'        => 'Test Story',
            'status'       => 'backlog',
            'size'         => null,
            'project_id'   => 1,
            'project_name' => 'Test Project',
            'updated_at'   => '2024-01-01 00:00:00',
        ];

        $this->currentDbStmt = $this->makeDbStmt(fetch: false, fetchAll: [$storyRow]);

        $this->makeController()->index();

        $data = $this->response->jsonPayload['data'];
        $this->assertNotEmpty($data);
        $this->assertJsonShape($data[0], [
            'id'     => 'integer',
            'title'  => 'string',
            'status' => 'string',
        ]);
    }

    // ===========================
    // POST /api/v1/stories/{id}/status
    // ===========================

    public function testUpdateStatusRejectsInvalidStatus(): void
    {
        $request = $this->makeJsonPostRequest(['status' => 'invalid-status']);
        $ctrl    = new ApiStoriesController($request, $this->response, $this->auth, $this->db, []);
        $ctrl->updateStatus('1');

        $this->assertSame(422, $this->response->jsonStatus);
        $this->assertArrayHasKey('error', $this->response->jsonPayload);
    }

    public function testUpdateStatusReturnsErrorForMissingStory(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);  // story not found
        $stmt->method('fetchAll')->willReturn([]);
        $this->db->method('query')->willReturn($stmt);

        $request = $this->makeJsonPostRequest(['status' => 'in_progress']);
        $ctrl    = new ApiStoriesController($request, $this->response, $this->auth, $this->db, []);
        $ctrl->updateStatus('99999');

        $this->assertSame(404, $this->response->jsonStatus);
    }

    // ===========================
    // GET /api/v1/stories/team
    // ===========================

    public function testTeamStoriesReturns200WithStoriesArray(): void
    {
        // All queries share the same stmt; fetch() supplies the team row,
        // fetchAll() returns empty stories — both are acceptable for this test.
        $this->currentDbStmt = $this->makeDbStmt(fetch: ['team' => 'backend'], fetchAll: []);

        $this->makeController()->teamStories();

        $this->assertSame(200, $this->response->jsonStatus);
        $this->assertArrayHasKey('data', $this->response->jsonPayload);
        $this->assertArrayHasKey('team', $this->response->jsonPayload);
    }

    // ===========================
    // GET /api/v1/stories/{id}
    // ===========================

    public function testShowReturns404ForMissingStory(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);
        $stmt->method('fetchAll')->willReturn([]);
        $this->db->method('query')->willReturn($stmt);

        $this->makeController()->show('99999');

        $this->assertSame(404, $this->response->jsonStatus);
        $this->assertArrayHasKey('error', $this->response->jsonPayload);
    }
}
