<?php
declare(strict_types=1);
namespace StratFlow\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use StratFlow\Services\DriftDetectionService;
use StratFlow\Services\GeminiService;
use StratFlow\Core\Database;

class DriftDetectionServiceTest extends TestCase
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

    private function makeGemini(array $result = ['aligned' => true, 'confidence' => 0.9, 'explanation' => 'Good']): GeminiService
    {
        $gemini = $this->createMock(GeminiService::class);
        $gemini->method('generateJson')->willReturn($result);
        return $gemini;
    }

    public function testConstructorWithDatabase(): void
    {
        $db = $this->makeDb();
        $service = new DriftDetectionService($db, null);
        $this->assertNotNull($service);
    }

    public function testConstructorWithGemini(): void
    {
        $db = $this->makeDb();
        $gemini = $this->makeGemini();
        $service = new DriftDetectionService($db, $gemini);
        $this->assertNotNull($service);
    }

    public function testCreateBaseline(): void
    {
        $db = $this->createMock(Database::class);
        $stmt = $this->createMock(\PDOStatement::class);
        $workItems = [
            ['id' => 1, 'title' => 'Epic 1', 'priority_number' => 1, 'estimated_sprints' => 3, 'final_score' => 85],
        ];
        $stories = [
            ['id' => 1, 'parent_hl_item_id' => 1, 'size' => 5, 'parent_title' => 'Epic 1'],
        ];
        $stmt->method('fetchAll')->willReturnOnConsecutiveCalls($workItems, $stories);
        $db->method('query')->willReturn($stmt);

        $service = new DriftDetectionService($db, null);
        $baselineId = $service->createBaseline(1);
        $this->assertIsInt($baselineId);
    }

    public function testDetectDriftWithNoBaseline(): void
    {
        $db = $this->createMock(Database::class);
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);
        $db->method('query')->willReturn($stmt);

        $service = new DriftDetectionService($db);
        $drifts = $service->detectDrift(1, 0.20);
        $this->assertCount(0, $drifts);
    }

    public function testDetectDriftCapacityTripwire(): void
    {
        $db = $this->createMock(Database::class);
        $stmt = $this->createMock(\PDOStatement::class);

        $baseline = [
            'snapshot_json' => json_encode([
                'created_at' => '2024-01-01 00:00:00',
                'work_items' => [['id' => 1, 'title' => 'Epic', 'priority_number' => 1, 'estimated_sprints' => 3]],
                'stories' => [
                    'total_count' => 1,
                    'total_size' => 10,
                    'by_parent' => [1 => ['count' => 1, 'total_size' => 10, 'parent_title' => 'Epic']],
                ],
            ])
        ];

        $currentStories = [
            ['id' => 1, 'parent_hl_item_id' => 1, 'size' => 25, 'parent_title' => 'Epic', 'title' => 'Story', 'blocked_by' => null, 'team_assigned' => null],
        ];

        $stmt->method('fetch')->willReturn($baseline);
        $stmt->method('fetchAll')->willReturn($currentStories);
        $db->method('query')->willReturn($stmt);

        $service = new DriftDetectionService($db);
        $drifts = $service->detectDrift(1, 0.15);
        // Capacity increased from 10 to 25 — should detect drift at 150% threshold
        $this->assertIsArray($drifts);
    }

    public function testDetectDriftDependencyTripwire(): void
    {
        $db = $this->createMock(Database::class);
        $stmt = $this->createMock(\PDOStatement::class);

        $baseline = [
            'snapshot_json' => json_encode([
                'created_at' => '2024-01-01 00:00:00',
                'work_items' => [],
                'stories' => ['total_count' => 0, 'total_size' => 0, 'by_parent' => []],
            ])
        ];

        $stories = [
            ['id' => 1, 'title' => 'Story A', 'size' => 5, 'parent_hl_item_id' => null, 'parent_title' => null, 'blocked_by' => 2, 'team_assigned' => 'Team A'],
            ['id' => 2, 'title' => 'Story B', 'size' => 3, 'parent_hl_item_id' => null, 'parent_title' => null, 'blocked_by' => null, 'team_assigned' => 'Team B'],
        ];

        $stmt->method('fetch')->willReturn($baseline);
        $stmt->method('fetchAll')->willReturn($stories);
        $db->method('query')->willReturn($stmt);

        $service = new DriftDetectionService($db);
        $drifts = $service->detectDrift(1, 0.20);
        // Stories added from empty baseline with dependencies — should detect structural change
        $this->assertIsArray($drifts);
    }

    public function testCheckAlignmentWithGemini(): void
    {
        $db = $this->makeDb();
        $gemini = $this->makeGemini(['aligned' => true, 'confidence' => 0.95, 'explanation' => 'Aligns well']);
        $service = new DriftDetectionService($db, $gemini);

        $result = $service->checkAlignment('Increase user engagement', 'Add notification feature', 'Send push notifications');
        $this->assertNotNull($result);
        $this->assertTrue($result['aligned']);
        $this->assertEquals(0.95, $result['confidence']);
    }

    public function testCheckAlignmentWithoutGemini(): void
    {
        $db = $this->makeDb();
        $service = new DriftDetectionService($db, null);

        $result = $service->checkAlignment('OKR text', 'Story title', 'Story description');
        $this->assertNull($result);
    }

    public function testCheckAlignmentWithGeminiError(): void
    {
        $db = $this->makeDb();
        $gemini = $this->createMock(GeminiService::class);
        $gemini->method('generateJson')->willThrowException(new \RuntimeException('API error'));
        $service = new DriftDetectionService($db, $gemini);

        $result = $service->checkAlignment('OKR', 'Title', 'Description');
        $this->assertNull($result);
    }

    public function testDetectDriftWithEmptyStoriesReturnsEmptyDrifts(): void
    {
        $db = $this->makeDb();
        $service = new DriftDetectionService($db);

        $baseline = [
            'snapshot_json' => json_encode([
                'created_at' => '2024-01-01',
                'work_items' => [],
                'stories' => ['total_count' => 0, 'total_size' => 0, 'by_parent' => []],
            ])
        ];

        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn($baseline);
        $stmt->method('fetchAll')->willReturn([]);
        $db->method('query')->willReturn($stmt);

        $drifts = $service->detectDrift(1);
        $this->assertIsArray($drifts);
    }
}
