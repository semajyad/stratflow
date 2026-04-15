<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Controllers;

use PHPUnit\Framework\Attributes\Test;
use StratFlow\Controllers\StoryQualityController;
use StratFlow\Tests\Support\ControllerTestCase;

/**
 * StoryQualityControllerTest
 *
 * Unit tests for StoryQualityController (story quality rules admin).
 * Tests all three public methods: index(), store(), delete().
 * Coverage target: ≥80% method coverage.
 */
final class StoryQualityControllerTest extends ControllerTestCase
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
    public function testIndexRendersStoryQualityRulesTemplate(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);
        $stmt->method('fetchAll')->willReturn([]);
        $this->db->method('query')->willReturn($stmt);

        $request = $this->makeGetRequest();
        $ctrl = new StoryQualityController($request, $this->response, $this->auth, $this->db, $this->config);

        $ctrl->index();

        $this->assertSame('admin/story-quality-rules', $this->response->renderedTemplate);
    }

    #[Test]
    public function testIndexSeedsDefaultRulesOnFirstVisit(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);
        $stmt->method('fetchAll')->willReturn([]);
        $this->db->method('query')->willReturn($stmt);

        $request = $this->makeGetRequest();
        $ctrl = new StoryQualityController($request, $this->response, $this->auth, $this->db, $this->config);

        $ctrl->index();

        $this->assertArrayHasKey('rules', $this->response->renderedData);
    }

    #[Test]
    public function testIndexPassesRulesToView(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);
        $stmt->method('fetchAll')->willReturn([
            ['id' => 1, 'rule_type' => 'splitting_pattern', 'label' => 'Feature/', 'is_default' => 1],
        ]);
        $this->db->method('query')->willReturn($stmt);

        $request = $this->makeGetRequest();
        $ctrl = new StoryQualityController($request, $this->response, $this->auth, $this->db, $this->config);

        $ctrl->index();

        $this->assertArrayHasKey('rules', $this->response->renderedData);
        $this->assertIsArray($this->response->renderedData['rules']);
    }

    #[Test]
    public function testIndexPassesUserToView(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);
        $stmt->method('fetchAll')->willReturn([]);
        $this->db->method('query')->willReturn($stmt);

        $request = $this->makeGetRequest();
        $ctrl = new StoryQualityController($request, $this->response, $this->auth, $this->db, $this->config);

        $ctrl->index();

        $this->assertArrayHasKey('user', $this->response->renderedData);
    }

    #[Test]
    public function testIndexClearsFlashMessagesAfterRender(): void
    {
        $_SESSION['flash_message'] = 'Test message';
        $_SESSION['flash_error'] = 'Test error';

        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);
        $stmt->method('fetchAll')->willReturn([]);
        $this->db->method('query')->willReturn($stmt);

        $request = $this->makeGetRequest();
        $ctrl = new StoryQualityController($request, $this->response, $this->auth, $this->db, $this->config);

        $ctrl->index();

        $this->assertFalse(isset($_SESSION['flash_message']));
        $this->assertFalse(isset($_SESSION['flash_error']));
    }

    #[Test]
    public function testStoreRedirectsWhenLabelEmpty(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);
        $stmt->method('fetchAll')->willReturn([]);
        $this->db->method('query')->willReturn($stmt);

        $request = $this->makePostRequest([
            'rule_type' => 'splitting_pattern',
            'label'     => '',
        ]);
        $ctrl = new StoryQualityController($request, $this->response, $this->auth, $this->db, $this->config);

        $ctrl->store();

        $this->assertSame('/app/admin/story-quality-rules', $this->response->redirectedTo);
        $this->assertArrayHasKey('flash_error', $_SESSION);
        $this->assertStringContainsString('Label', $_SESSION['flash_error']);
    }

    #[Test]
    public function testStoreRedirectsWhenLabelOnlyWhitespace(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);
        $stmt->method('fetchAll')->willReturn([]);
        $this->db->method('query')->willReturn($stmt);

        $request = $this->makePostRequest([
            'rule_type' => 'splitting_pattern',
            'label'     => '   ',
        ]);
        $ctrl = new StoryQualityController($request, $this->response, $this->auth, $this->db, $this->config);

        $ctrl->store();

        $this->assertSame('/app/admin/story-quality-rules', $this->response->redirectedTo);
        $this->assertArrayHasKey('flash_error', $_SESSION);
    }

    #[Test]
    public function testStoreRedirectsWhenRuleTypeInvalid(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);
        $stmt->method('fetchAll')->willReturn([]);
        $this->db->method('query')->willReturn($stmt);

        $request = $this->makePostRequest([
            'rule_type' => 'invalid_type',
            'label'     => 'My Rule',
        ]);
        $ctrl = new StoryQualityController($request, $this->response, $this->auth, $this->db, $this->config);

        $ctrl->store();

        $this->assertSame('/app/admin/story-quality-rules', $this->response->redirectedTo);
        $this->assertArrayHasKey('flash_error', $_SESSION);
        $this->assertStringContainsString('Invalid', $_SESSION['flash_error']);
    }

    #[Test]
    public function testStoreSucceedsWithSplittingPattern(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);
        $stmt->method('fetchAll')->willReturn([]);
        $this->db->method('query')->willReturn($stmt);
        $this->db->method('lastInsertId')->willReturn('10');

        $request = $this->makePostRequest([
            'rule_type' => 'splitting_pattern',
            'label'     => 'Feature/',
        ]);
        $ctrl = new StoryQualityController($request, $this->response, $this->auth, $this->db, $this->config);

        $ctrl->store();

        $this->assertSame('/app/admin/story-quality-rules', $this->response->redirectedTo);
        $this->assertArrayHasKey('flash_message', $_SESSION);
        $this->assertStringContainsString('added', $_SESSION['flash_message']);
    }

    #[Test]
    public function testStoreSucceedsWithMandatoryCondition(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);
        $stmt->method('fetchAll')->willReturn([]);
        $this->db->method('query')->willReturn($stmt);
        $this->db->method('lastInsertId')->willReturn('11');

        $request = $this->makePostRequest([
            'rule_type' => 'mandatory_condition',
            'label'     => 'Must include acceptance criteria',
        ]);
        $ctrl = new StoryQualityController($request, $this->response, $this->auth, $this->db, $this->config);

        $ctrl->store();

        $this->assertSame('/app/admin/story-quality-rules', $this->response->redirectedTo);
        $this->assertArrayHasKey('flash_message', $_SESSION);
    }

    #[Test]
    public function testStoreTrimsSingleLabelWhitespace(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);
        $stmt->method('fetchAll')->willReturn([]);
        $this->db->method('query')->willReturn($stmt);
        $this->db->method('lastInsertId')->willReturn('12');

        $request = $this->makePostRequest([
            'rule_type' => 'splitting_pattern',
            'label'     => '  Bugfix/  ',
        ]);
        $ctrl = new StoryQualityController($request, $this->response, $this->auth, $this->db, $this->config);

        $ctrl->store();

        $this->assertSame('/app/admin/story-quality-rules', $this->response->redirectedTo);
        $this->assertArrayHasKey('flash_message', $_SESSION);
    }

    #[Test]
    public function testDeleteRedirectsToAdminPage(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);
        $stmt->method('fetchAll')->willReturn([]);
        $this->db->method('query')->willReturn($stmt);

        $request = $this->makePostRequest([]);
        $ctrl = new StoryQualityController($request, $this->response, $this->auth, $this->db, $this->config);

        $ctrl->delete(1);

        $this->assertSame('/app/admin/story-quality-rules', $this->response->redirectedTo);
    }

    #[Test]
    public function testDeleteSetsFlashMessage(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);
        $stmt->method('fetchAll')->willReturn([]);
        $this->db->method('query')->willReturn($stmt);

        $request = $this->makePostRequest([]);
        $ctrl = new StoryQualityController($request, $this->response, $this->auth, $this->db, $this->config);

        $ctrl->delete(1);

        $this->assertArrayHasKey('flash_message', $_SESSION);
        $this->assertStringContainsString('removed', $_SESSION['flash_message']);
    }

    #[Test]
    public function testDeleteWithIntegerId(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);
        $stmt->method('fetchAll')->willReturn([]);
        $this->db->method('query')->willReturn($stmt);

        $request = $this->makePostRequest([]);
        $ctrl = new StoryQualityController($request, $this->response, $this->auth, $this->db, $this->config);

        $ctrl->delete(42);

        $this->assertSame('/app/admin/story-quality-rules', $this->response->redirectedTo);
        $this->assertArrayHasKey('flash_message', $_SESSION);
    }

    #[Test]
    public function testDeleteWithStringId(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);
        $stmt->method('fetchAll')->willReturn([]);
        $this->db->method('query')->willReturn($stmt);

        $request = $this->makePostRequest([]);
        $ctrl = new StoryQualityController($request, $this->response, $this->auth, $this->db, $this->config);

        $ctrl->delete('99');

        $this->assertSame('/app/admin/story-quality-rules', $this->response->redirectedTo);
        $this->assertArrayHasKey('flash_message', $_SESSION);
    }
}
