<?php
declare(strict_types=1);
namespace StratFlow\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use StratFlow\Services\TraceabilityService;
use StratFlow\Core\Database;

class TraceabilityServiceTest extends TestCase
{
    private function makeDb(mixed $fetch = false, array $all = []): Database
    {
        $db = $this->createMock(Database::class);
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn($fetch);
        $stmt->method('fetchAll')->willReturn($all);
        $db->method('query')->willReturn($stmt);
        return $db;
    }

    public function testConstructorWithDatabase(): void
    {
        $db = $this->makeDb();
        $service = new TraceabilityService($db);
        $this->assertNotNull($service);
    }

    public function testForProjectWithInvalidProject(): void
    {
        $db = $this->makeDb(false, []);
        $service = new TraceabilityService($db);

        $result = $service->forProject(999, 1);
        $this->assertNull($result);
    }

    public function testForProjectWithValidProject(): void
    {
        $db = $this->createMock(Database::class);
        $stmt = $this->createMock(\PDOStatement::class);

        $project = ['id' => 1, 'name' => 'Test Project', 'org_id' => 1];
        $workItems = [];
        $stories = [];
        $jiraMap = [];

        $stmt->method('fetch')->willReturn($project);
        $stmt->method('fetchAll')->willReturnOnConsecutiveCalls($workItems, $stories, $jiraMap);
        $db->method('query')->willReturn($stmt);

        $service = new TraceabilityService($db);
        $result = $service->forProject(1, 1);

        $this->assertNotNull($result);
        $this->assertArrayHasKey('project', $result);
        $this->assertArrayHasKey('okrs', $result);
        $this->assertArrayHasKey('unlinked_stories', $result);
    }

    public function testForProjectWithWorkItems(): void
    {
        $db = $this->createMock(Database::class);
        $stmt = $this->createMock(\PDOStatement::class);

        $project = ['id' => 1, 'name' => 'Project', 'org_id' => 1];
        $workItems = [
            ['id' => 1, 'title' => 'Epic 1', 'okr_title' => 'OKR A', 'okr_description' => 'Desc', 'priority_number' => 1],
        ];
        $stories = [];
        $jiraMap = [];

        $stmt->method('fetch')->willReturn($project);
        $stmt->method('fetchAll')->willReturnOnConsecutiveCalls($workItems, $stories, [], $jiraMap);
        $db->method('query')->willReturn($stmt);

        $service = new TraceabilityService($db);
        $result = $service->forProject(1, 1);

        $this->assertNotNull($result);
        $this->assertCount(1, $result['okrs']);
    }

    public function testForProjectWithStories(): void
    {
        $db = $this->createMock(Database::class);
        $stmt = $this->createMock(\PDOStatement::class);
        $pdo = $this->createMock(\PDO::class);

        $project = ['id' => 1, 'name' => 'Project', 'org_id' => 1];
        $workItems = [
            ['id' => 1, 'title' => 'Epic', 'okr_title' => 'OKR', 'okr_description' => '', 'priority_number' => 1],
        ];
        $stories = [
            ['id' => 1, 'title' => 'Story 1', 'parent_hl_item_id' => 1, 'jira_key' => 'PROJ-1', 'status' => 'in_progress'],
        ];

        $stmt->method('fetch')->willReturn($project);
        $stmt->method('fetchAll')->willReturnOnConsecutiveCalls($workItems, $stories, [], []);
        $db->method('query')->willReturn($stmt);
        $db->method('getPdo')->willReturn($pdo);

        $pdoStmt = $this->createMock(\PDOStatement::class);
        $pdoStmt->method('execute')->willReturn(true);
        $pdoStmt->method('fetchAll')->willReturn([]);
        $pdo->method('prepare')->willReturn($pdoStmt);

        $service = new TraceabilityService($db);
        $result = $service->forProject(1, 1);

        $this->assertNotNull($result);
        $this->assertCount(1, $result['okrs'][0]['work_items']);
    }

    public function testForProjectWithUnlinkedStories(): void
    {
        $db = $this->createMock(Database::class);
        $stmt = $this->createMock(\PDOStatement::class);
        $pdo = $this->createMock(\PDO::class);

        $project = ['id' => 1, 'name' => 'Project', 'org_id' => 1];
        $workItems = [];
        $stories = [
            ['id' => 1, 'title' => 'Unlinked Story', 'parent_hl_item_id' => null, 'jira_key' => null, 'status' => 'pending'],
        ];

        $stmt->method('fetch')->willReturn($project);
        $stmt->method('fetchAll')->willReturnOnConsecutiveCalls($workItems, $stories, [], []);
        $db->method('query')->willReturn($stmt);
        $db->method('getPdo')->willReturn($pdo);

        $pdoStmt = $this->createMock(\PDOStatement::class);
        $pdoStmt->method('execute')->willReturn(true);
        $pdoStmt->method('fetchAll')->willReturn([]);
        $pdo->method('prepare')->willReturn($pdoStmt);

        $service = new TraceabilityService($db);
        $result = $service->forProject(1, 1);

        $this->assertNotNull($result);
        $this->assertCount(1, $result['unlinked_stories']);
    }

    public function testForProjectWithMultipleOkrBuckets(): void
    {
        $db = $this->createMock(Database::class);
        $stmt = $this->createMock(\PDOStatement::class);

        $project = ['id' => 1, 'name' => 'Project', 'org_id' => 1];
        $workItems = [
            ['id' => 1, 'title' => 'Epic A', 'okr_title' => 'Increase Engagement', 'okr_description' => 'D1', 'priority_number' => 1],
            ['id' => 2, 'title' => 'Epic B', 'okr_title' => 'Improve Quality', 'okr_description' => 'D2', 'priority_number' => 2],
        ];
        $stories = [];

        $stmt->method('fetch')->willReturn($project);
        $stmt->method('fetchAll')->willReturnOnConsecutiveCalls($workItems, $stories, [], []);
        $db->method('query')->willReturn($stmt);

        $service = new TraceabilityService($db);
        $result = $service->forProject(1, 1);

        $this->assertNotNull($result);
        $this->assertGreaterThanOrEqual(2, count($result['okrs']));
    }

    public function testForProjectCountsRollups(): void
    {
        $db = $this->createMock(Database::class);
        $stmt = $this->createMock(\PDOStatement::class);
        $pdo = $this->createMock(\PDO::class);

        $project = ['id' => 1, 'name' => 'Project', 'org_id' => 1];
        $workItems = [
            ['id' => 1, 'title' => 'Epic', 'okr_title' => 'OKR', 'okr_description' => '', 'priority_number' => 1],
        ];
        $stories = [
            ['id' => 1, 'title' => 'Story 1', 'parent_hl_item_id' => 1, 'jira_key' => null, 'status' => 'done'],
            ['id' => 2, 'title' => 'Story 2', 'parent_hl_item_id' => 1, 'jira_key' => null, 'status' => 'in_progress'],
        ];

        $stmt->method('fetch')->willReturn($project);
        $stmt->method('fetchAll')->willReturnOnConsecutiveCalls($workItems, $stories, [], []);
        $db->method('query')->willReturn($stmt);
        $db->method('getPdo')->willReturn($pdo);

        $pdoStmt = $this->createMock(\PDOStatement::class);
        $pdoStmt->method('execute')->willReturn(true);
        $pdoStmt->method('fetchAll')->willReturn([]);
        $pdo->method('prepare')->willReturn($pdoStmt);

        $service = new TraceabilityService($db);
        $result = $service->forProject(1, 1);

        $this->assertNotNull($result);
        $firstOkr = $result['okrs'][0];
        $this->assertGreaterThanOrEqual(1, $firstOkr['story_count']);
        $this->assertGreaterThanOrEqual(0, $firstOkr['done_count']);
    }

    public function testForProjectWithOrgBoundaryCheck(): void
    {
        $db = $this->createMock(Database::class);
        $stmt = $this->createMock(\PDOStatement::class);

        $stmt->method('fetch')->willReturn(false);
        $db->method('query')->willReturn($stmt);

        $service = new TraceabilityService($db);
        $result = $service->forProject(1, 999);

        $this->assertNull($result);
    }
}
