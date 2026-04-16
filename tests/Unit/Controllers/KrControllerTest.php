<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Controllers;

use PHPUnit\Framework\Attributes\Test;
use StratFlow\Controllers\KrController;
use StratFlow\Tests\Support\ControllerTestCase;

/**
 * KrControllerTest
 *
 * Unit tests for KrController (CRUD for Key Results).
 * Tests all three public methods: store(), update(), delete().
 * Coverage target: ≥80% method coverage.
 */
final class KrControllerTest extends ControllerTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];

        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);
        $stmt->method('fetchAll')->willReturn([]);
        $this->db->method('query')->willReturn($stmt);
        $this->db->method('lastInsertId')->willReturn('99');

        $this->actingAs([
            'id'        => 1,
            'org_id'    => 10,
            'role'      => 'org_admin',
            'email'     => 'admin@test.invalid',
            'is_active' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    #[Test]
    public function testStoreReturnsBadRequestWhenWorkItemIdMissing(): void
    {
        $request = $this->makePostRequest([
            'hl_work_item_id' => 0,
            'title'           => 'My KR',
        ]);

        $ctrl = new KrController($request, $this->response, $this->auth, $this->db, $this->config);

        ob_start();
        $ctrl->store();
        $output = ob_get_clean();

        $data = json_decode($output, true);
        $this->assertArrayHasKey('error', $data);
        $this->assertSame('High Level Work Item ID required', $data['error']);
    }

    #[Test]
    public function testStoreReturnsForbiddenWhenWorkItemDoesNotBelongToOrg(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(['org_id' => 20]);
        $this->db->method('query')->willReturn($stmt);

        $request = $this->makePostRequest([
            'hl_work_item_id' => 5,
            'title'           => 'My KR',
        ]);

        $ctrl = new KrController($request, $this->response, $this->auth, $this->db, $this->config);

        ob_start();
        $ctrl->store();
        $output = ob_get_clean();

        $data = json_decode($output, true);
        $this->assertArrayHasKey('error', $data);
        $this->assertSame('Forbidden', $data['error']);
    }

    #[Test]
    public function testStoreReturnsBadRequestWhenTitleEmpty(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(['org_id' => 10]);
        $this->db = $this->createMock(\StratFlow\Core\Database::class);
        $this->db->method('query')->willReturn($stmt);

        $request = $this->makePostRequest([
            'hl_work_item_id' => 5,
            'title'           => '',
        ]);

        $ctrl = new KrController($request, $this->response, $this->auth, $this->db, $this->config);

        ob_start();
        $ctrl->store();
        $output = ob_get_clean();

        $data = json_decode($output, true);
        $this->assertArrayHasKey('error', $data);
        $this->assertSame('title required', $data['error']);
    }

    #[Test]
    public function testStoreReturnsBadRequestWhenTitleOnlyWhitespace(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(['org_id' => 10]);
        $this->db = $this->createMock(\StratFlow\Core\Database::class);
        $this->db->method('query')->willReturn($stmt);

        $request = $this->makePostRequest([
            'hl_work_item_id' => 5,
            'title'           => '   ',
        ]);

        $ctrl = new KrController($request, $this->response, $this->auth, $this->db, $this->config);

        ob_start();
        $ctrl->store();
        $output = ob_get_clean();

        $data = json_decode($output, true);
        $this->assertArrayHasKey('error', $data);
        $this->assertSame('title required', $data['error']);
    }

    #[Test]
    public function testStoreSucceedsWithValidData(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(['org_id' => 10]);
        $this->db = $this->createMock(\StratFlow\Core\Database::class);
        $this->db->method('query')->willReturn($stmt);
        $this->db->method('lastInsertId')->willReturn('42');

        $request = $this->makePostRequest([
            'hl_work_item_id'    => 5,
            'title'              => 'Increase Q4 Revenue',
            'metric_description' => 'Revenue target',
            'baseline_value'     => '100k',
            'target_value'       => '150k',
            'status'             => 'on_track',
            'display_order'      => 1,
        ]);

        $ctrl = new KrController($request, $this->response, $this->auth, $this->db, $this->config);

        ob_start();
        $ctrl->store();
        $output = ob_get_clean();

        $data = json_decode($output, true);
        $this->assertArrayHasKey('ok', $data);
        $this->assertTrue($data['ok']);
        $this->assertArrayHasKey('id', $data);
        $this->assertSame(42, $data['id']);
    }

    #[Test]
    public function testStoreSanitisesInvalidStatus(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(['org_id' => 10]);
        $this->db = $this->createMock(\StratFlow\Core\Database::class);
        $this->db->method('query')->willReturn($stmt);
        $this->db->method('lastInsertId')->willReturn('43');

        $request = $this->makePostRequest([
            'hl_work_item_id' => 5,
            'title'           => 'KR with invalid status',
            'status'          => 'invalid_status',
        ]);

        $ctrl = new KrController($request, $this->response, $this->auth, $this->db, $this->config);

        ob_start();
        $ctrl->store();
        $output = ob_get_clean();

        $data = json_decode($output, true);
        $this->assertArrayHasKey('ok', $data);
        $this->assertTrue($data['ok']);
    }

    #[Test]
    public function testStoreHandlesNullMetricDescription(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(['org_id' => 10]);
        $this->db = $this->createMock(\StratFlow\Core\Database::class);
        $this->db->method('query')->willReturn($stmt);
        $this->db->method('lastInsertId')->willReturn('44');

        $request = $this->makePostRequest([
            'hl_work_item_id' => 5,
            'title'           => 'KR without metric description',
        ]);

        $ctrl = new KrController($request, $this->response, $this->auth, $this->db, $this->config);

        ob_start();
        $ctrl->store();
        $output = ob_get_clean();

        $data = json_decode($output, true);
        $this->assertArrayHasKey('ok', $data);
        $this->assertTrue($data['ok']);
    }

    #[Test]
    public function testUpdateReturns404WhenKrNotFound(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);
        $this->db->method('query')->willReturn($stmt);

        $request = $this->makePostRequest([
            'title' => 'Updated Title',
        ]);

        $ctrl = new KrController($request, $this->response, $this->auth, $this->db, $this->config);

        ob_start();
        $ctrl->update(999);
        $output = ob_get_clean();

        $data = json_decode($output, true);
        $this->assertArrayHasKey('error', $data);
        $this->assertSame('Not found', $data['error']);
    }

    #[Test]
    public function testUpdateSucceedsWithTitle(): void
    {
        // Re-init DB mock to avoid state from setUp()
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn([
            'id'       => 1,
            'org_id'   => 10,
            'title'    => 'Old Title',
            'status'   => 'on_track',
        ]);
        $this->db = $this->createMock(\StratFlow\Core\Database::class);
        $this->db->method('query')->willReturn($stmt);

        $request = $this->makePostRequest([
            'title' => 'New Title',
        ]);

        $ctrl = new KrController($request, $this->response, $this->auth, $this->db, $this->config);

        ob_start();
        $ctrl->update(1);
        $output = ob_get_clean();

        $data = json_decode($output, true);
        $this->assertArrayHasKey('ok', $data);
        $this->assertTrue($data['ok']);
    }

    #[Test]
    public function testUpdateSucceedsWithStatus(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn([
            'id'     => 1,
            'org_id' => 10,
            'title'  => 'Test KR',
            'status' => 'on_track',
        ]);
        $this->db = $this->createMock(\StratFlow\Core\Database::class);
        $this->db->method('query')->willReturn($stmt);

        $request = $this->makePostRequest([
            'status' => 'at_risk',
        ]);

        $ctrl = new KrController($request, $this->response, $this->auth, $this->db, $this->config);

        ob_start();
        $ctrl->update(1);
        $output = ob_get_clean();

        $data = json_decode($output, true);
        $this->assertArrayHasKey('ok', $data);
        $this->assertTrue($data['ok']);
    }

    #[Test]
    public function testUpdateSucceedsWithMultipleFields(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn([
            'id'                 => 1,
            'org_id'             => 10,
            'title'              => 'Old',
            'metric_description' => 'Old desc',
            'status'             => 'on_track',
        ]);
        $this->db = $this->createMock(\StratFlow\Core\Database::class);
        $this->db->method('query')->willReturn($stmt);

        $request = $this->makePostRequest([
            'title'              => 'New Title',
            'metric_description' => 'New desc',
            'target_value'       => '200k',
            'status'             => 'achieved',
        ]);

        $ctrl = new KrController($request, $this->response, $this->auth, $this->db, $this->config);

        ob_start();
        $ctrl->update(1);
        $output = ob_get_clean();

        $data = json_decode($output, true);
        $this->assertArrayHasKey('ok', $data);
        $this->assertTrue($data['ok']);
    }

    #[Test]
    public function testUpdateIgnoresNullFields(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn([
            'id'     => 1,
            'org_id' => 10,
            'title'  => 'KR Title',
            'status' => 'on_track',
        ]);
        $this->db = $this->createMock(\StratFlow\Core\Database::class);
        $this->db->method('query')->willReturn($stmt);

        $request = $this->makePostRequest([]);

        $ctrl = new KrController($request, $this->response, $this->auth, $this->db, $this->config);

        ob_start();
        $ctrl->update(1);
        $output = ob_get_clean();

        $data = json_decode($output, true);
        $this->assertArrayHasKey('ok', $data);
        $this->assertTrue($data['ok']);
    }

    #[Test]
    public function testUpdateSanitisesStatusField(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn([
            'id'     => 1,
            'org_id' => 10,
            'title'  => 'KR Title',
            'status' => 'on_track',
        ]);
        $this->db = $this->createMock(\StratFlow\Core\Database::class);
        $this->db->method('query')->willReturn($stmt);

        $request = $this->makePostRequest([
            'status' => 'bad_status',
        ]);

        $ctrl = new KrController($request, $this->response, $this->auth, $this->db, $this->config);

        ob_start();
        $ctrl->update(1);
        $output = ob_get_clean();

        $data = json_decode($output, true);
        $this->assertArrayHasKey('ok', $data);
        $this->assertTrue($data['ok']);
    }

    #[Test]
    public function testDeleteReturns404WhenKrNotFound(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);
        $this->db->method('query')->willReturn($stmt);

        $request = $this->makePostRequest([]);
        $ctrl = new KrController($request, $this->response, $this->auth, $this->db, $this->config);

        ob_start();
        $ctrl->delete(999);
        $output = ob_get_clean();

        $data = json_decode($output, true);
        $this->assertArrayHasKey('error', $data);
        $this->assertSame('Not found', $data['error']);
    }

    #[Test]
    public function testDeleteSucceedsWhenKrFound(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn([
            'id'     => 1,
            'org_id' => 10,
            'title'  => 'KR to Delete',
        ]);
        $this->db = $this->createMock(\StratFlow\Core\Database::class);
        $this->db->method('query')->willReturn($stmt);

        $request = $this->makePostRequest([]);
        $ctrl = new KrController($request, $this->response, $this->auth, $this->db, $this->config);

        ob_start();
        $ctrl->delete(1);
        $output = ob_get_clean();

        $data = json_decode($output, true);
        $this->assertArrayHasKey('ok', $data);
        $this->assertTrue($data['ok']);
    }

    #[Test]
    public function testDeleteWithDifferentId(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn([
            'id'     => 5,
            'org_id' => 10,
            'title'  => 'KR to Delete',
        ]);
        $this->db = $this->createMock(\StratFlow\Core\Database::class);
        $this->db->method('query')->willReturn($stmt);

        $request = $this->makePostRequest([]);
        $ctrl = new KrController($request, $this->response, $this->auth, $this->db, $this->config);

        ob_start();
        $ctrl->delete(5);
        $output = ob_get_clean();

        $data = json_decode($output, true);
        $this->assertArrayHasKey('ok', $data);
        $this->assertTrue($data['ok']);
    }
}
