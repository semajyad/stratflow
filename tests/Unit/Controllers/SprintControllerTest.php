<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Controllers;

use StratFlow\Controllers\SprintController;
use StratFlow\Tests\Support\ControllerTestCase;
use StratFlow\Tests\Support\FakeRequest;

class SprintControllerTest extends ControllerTestCase
{
    private array $user = ['id' => 1, 'org_id' => 10, 'role' => 'org_admin', 'email' => 'a@t.invalid', 'is_active' => 1];
    private array $project = ['id' => 5, 'org_id' => 10, 'name' => 'Test', 'visibility' => 'everyone'];
    private array $sprint = ['id' => 1, 'project_id' => 5, 'name' => 'Sprint 1', 'team_capacity' => 20, 'start_date' => '2025-01-01', 'end_date' => '2025-01-14', 'team_id' => null];
    private array $story = ['id' => 1, 'project_id' => 5, 'title' => 'Story', 'size' => 3, 'priority_number' => 1, 'blocked_by' => null, 'status' => 'backlog'];

    protected function setUp(): void
    {
        parent::setUp();
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $this->db->method('tableExists')->willReturn(false);
        $this->actingAs($this->user);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    private function ctrl(?FakeRequest $r = null): SprintController
    {
        return new SprintController($r ?? $this->makeGetRequest(), $this->response, $this->auth, $this->db, $this->config);
    }

    /**
     * Create a PDOStatement mock with predictable fetch/fetchAll results.
     *
     * @param mixed $fetch Value to return from fetch() (null becomes false)
     * @param array $all Array to return from fetchAll()
     * @return \PDOStatement Mock statement with configured behavior
     */
    private function stmt(mixed $fetch, array $all = []): \PDOStatement
    {
        $s = $this->createMock(\PDOStatement::class);
        // Return false for fetch() when $fetch is null (PDO behavior)
        $s->method('fetch')->willReturn($fetch === null ? false : $fetch);
        $s->method('fetchAll')->willReturn($all);
        return $s;
    }

    // ===== index() =====

    public function testIndexProjectNotFound(): void
    {
        $this->db->method('query')->willReturn($this->stmt(null));
        $req = $this->makeGetRequest(['project_id' => '5']);
        $this->ctrl($req)->index();
        $this->assertSame('/app/home', $this->response->redirectedTo);
    }

    public function testIndexWithMultipleSprints(): void
    {
        $sprint2 = ['id' => 2, 'project_id' => 5, 'name' => 'Sprint 2', 'team_capacity' => 25, 'start_date' => '2025-01-15', 'end_date' => '2025-01-29', 'team_id' => null];
        $team = ['id' => 1, 'org_id' => 10, 'name' => 'Team A'];
        $this->db->method('query')->willReturnOnConsecutiveCalls(
            $this->stmt($this->project),
            $this->stmt(null, [$this->sprint, $sprint2]),
            $this->stmt(null, [['id' => 10, 'sprint_id' => 1]]),
            $this->stmt(null, [['id' => 11, 'sprint_id' => 2]]),
            $this->stmt(null, [['id' => 1, 'title' => 'Unalloc Story', 'size' => 5, 'priority_number' => 3]]),
            $this->stmt(null, [$team]),
            $this->stmt(null),
            $this->stmt(null)
        );
        $req = $this->makeGetRequest(['project_id' => '5']);
        $this->ctrl($req)->index();
        $this->assertSame('sprints', $this->response->renderedTemplate);
        $this->assertCount(2, $this->response->renderedData['sprints']);
        $this->assertCount(1, $this->response->renderedData['unallocated']);
        $this->assertCount(1, $this->response->renderedData['teams']);
    }

    public function testIndexClearsFlashMessages(): void
    {
        $_SESSION['flash_message'] = 'Test message';
        $_SESSION['flash_error'] = 'Test error';
        $this->db->method('query')->willReturnOnConsecutiveCalls(
            $this->stmt($this->project),
            $this->stmt(null, []),
            $this->stmt(null, []),
            $this->stmt(null, []),
            $this->stmt(null, []),
            $this->stmt(null),
            $this->stmt(null)
        );
        $req = $this->makeGetRequest(['project_id' => '5']);
        $this->ctrl($req)->index();
        $this->assertArrayNotHasKey('flash_message', $_SESSION);
        $this->assertArrayNotHasKey('flash_error', $_SESSION);
    }

    // ===== jiraDefaults() =====

    public function testJiraDefaultsProjectNotFound(): void
    {
        $this->db->method('query')->willReturn($this->stmt(null));
        $req = $this->makeGetRequest(['project_id' => '5', 'board_id' => '0']);
        $this->ctrl($req)->jiraDefaults();
        $this->assertSame(404, $this->response->jsonStatus);
        $this->assertArrayHasKey('error', $this->response->jsonPayload);
    }

    public function testJiraDefaultsNoExistingSprints(): void
    {
        $this->db->method('query')->willReturnOnConsecutiveCalls(
            $this->stmt($this->project),
            $this->stmt(null, [])
        );
        $req = $this->makeGetRequest(['project_id' => '5', 'board_id' => '0']);
        $this->ctrl($req)->jiraDefaults();
        $this->assertSame(200, $this->response->jsonStatus);
        $this->assertSame(1, $this->response->jsonPayload['next_sprint_number']);
    }

    public function testJiraDefaultsWithExistingSprints(): void
    {
        $this->db->method('query')->willReturnOnConsecutiveCalls(
            $this->stmt($this->project),
            $this->stmt(null, [
                ['id' => 1, 'name' => 'Sprint 1'],
                ['id' => 2, 'name' => 'Sprint 2'],
            ])
        );
        $req = $this->makeGetRequest(['project_id' => '5', 'board_id' => '0']);
        $this->ctrl($req)->jiraDefaults();
        $this->assertSame(200, $this->response->jsonStatus);
        $this->assertSame(3, $this->response->jsonPayload['next_sprint_number']);
    }

    public function testJiraDefaultsExtractsSprintNumberFromName(): void
    {
        $this->db->method('query')->willReturnOnConsecutiveCalls(
            $this->stmt($this->project),
            $this->stmt(null, [
                ['id' => 1, 'name' => 'Release Sprint 5'],
            ])
        );
        $req = $this->makeGetRequest(['project_id' => '5', 'board_id' => '0']);
        $this->ctrl($req)->jiraDefaults();
        $this->assertSame(6, $this->response->jsonPayload['next_sprint_number']);
    }

    public function testJiraDefaultsReturnsDefaults(): void
    {
        $this->db->method('query')->willReturnOnConsecutiveCalls(
            $this->stmt($this->project),
            $this->stmt(null, [])
        );
        $req = $this->makeGetRequest(['project_id' => '5', 'board_id' => '0']);
        $this->ctrl($req)->jiraDefaults();
        $payload = $this->response->jsonPayload;
        $this->assertSame(1, $payload['next_sprint_number']);
        $this->assertSame(14, $payload['sprint_length_days']);
        $this->assertNull($payload['suggested_start']);
        $this->assertNull($payload['suggested_capacity']);
    }

    // ===== store() =====

    public function testStoreProjectNotFound(): void
    {
        $this->db->method('query')->willReturn($this->stmt(null));
        $req = $this->makePostRequest(['project_id' => '5', 'name' => 'New Sprint']);
        $this->ctrl($req)->store();
        $this->assertSame('/app/home', $this->response->redirectedTo);
    }

    public function testStoreNameEmpty(): void
    {
        $this->db->method('query')->willReturn($this->stmt($this->project));
        $req = $this->makePostRequest(['project_id' => '5', 'name' => '']);
        $this->ctrl($req)->store();
        $this->assertSame('/app/sprints?project_id=5', $this->response->redirectedTo);
        $this->assertSame('Sprint name is required.', $_SESSION['flash_error']);
    }

    public function testStoreSuccess(): void
    {
        $this->db->method('query')->willReturnOnConsecutiveCalls(
            $this->stmt($this->project),
            $this->stmt(null)
        );
        $req = $this->makePostRequest(['project_id' => '5', 'name' => 'Sprint 2', 'start_date' => '2025-01-15', 'end_date' => '2025-01-29', 'team_capacity' => '30']);
        $this->ctrl($req)->store();
        $this->assertSame('/app/sprints?project_id=5', $this->response->redirectedTo);
        $this->assertSame('Sprint created.', $_SESSION['flash_message']);
    }

    public function testStoreWithTeamId(): void
    {
        $this->db->method('query')->willReturnOnConsecutiveCalls(
            $this->stmt($this->project),
            $this->stmt(null)
        );
        $req = $this->makePostRequest(['project_id' => '5', 'name' => 'Sprint', 'team_capacity' => '20', 'team_id' => '3']);
        $this->ctrl($req)->store();
        $this->assertSame('/app/sprints?project_id=5', $this->response->redirectedTo);
        $this->assertSame('Sprint created.', $_SESSION['flash_message']);
    }

    public function testStoreWithoutCapacity(): void
    {
        $this->db->method('query')->willReturnOnConsecutiveCalls(
            $this->stmt($this->project),
            $this->stmt(null)
        );
        $req = $this->makePostRequest(['project_id' => '5', 'name' => 'Sprint']);
        $this->ctrl($req)->store();
        $this->assertSame('/app/sprints?project_id=5', $this->response->redirectedTo);
    }

    // ===== update($id) =====

    public function testUpdateSprintNotFound(): void
    {
        $this->db->method('query')->willReturn($this->stmt(null));
        $this->ctrl()->update(99);
        $this->assertSame('/app/home', $this->response->redirectedTo);
    }

    public function testUpdateProjectNotFound(): void
    {
        $this->db->method('query')->willReturnOnConsecutiveCalls(
            $this->stmt($this->sprint),
            $this->stmt(null)
        );
        $this->ctrl()->update(1);
        $this->assertSame('/app/home', $this->response->redirectedTo);
    }

    public function testUpdateSuccess(): void
    {
        $this->db->method('query')->willReturnOnConsecutiveCalls(
            $this->stmt($this->sprint),
            $this->stmt($this->project),
            $this->stmt(null)
        );
        $req = $this->makePostRequest(['name' => 'Updated Sprint', 'start_date' => '2025-01-05', 'end_date' => '2025-01-20', 'team_capacity' => '25']);
        $this->ctrl($req)->update(1);
        $this->assertSame('/app/sprints?project_id=5', $this->response->redirectedTo);
        $this->assertSame('Sprint updated.', $_SESSION['flash_message']);
    }

    public function testUpdatePreservesExistingName(): void
    {
        $this->db->method('query')->willReturnOnConsecutiveCalls(
            $this->stmt($this->sprint),
            $this->stmt($this->project),
            $this->stmt(null)
        );
        $req = $this->makePostRequest(['start_date' => '2025-01-05', 'end_date' => '2025-01-20']);
        $this->ctrl($req)->update(1);
        $this->assertSame('/app/sprints?project_id=5', $this->response->redirectedTo);
        $this->assertSame('Sprint updated.', $_SESSION['flash_message']);
    }

    // ===== delete($id) =====

    public function testDeleteSprintNotFound(): void
    {
        $this->db->method('query')->willReturn($this->stmt(null));
        $this->ctrl()->delete(99);
        $this->assertSame('/app/home', $this->response->redirectedTo);
    }

    public function testDeleteProjectNotEditable(): void
    {
        $this->db->method('query')->willReturnOnConsecutiveCalls(
            $this->stmt($this->sprint),
            $this->stmt(null)
        );
        $this->ctrl()->delete(1);
        $this->assertSame('/app/home', $this->response->redirectedTo);
    }

    public function testDeleteSuccess(): void
    {
        $this->db->method('query')->willReturnOnConsecutiveCalls(
            $this->stmt($this->sprint),
            $this->stmt($this->project),
            $this->stmt(null),
            $this->stmt(null)
        );
        $this->ctrl()->delete(1);
        $this->assertSame('/app/sprints?project_id=5', $this->response->redirectedTo);
        $this->assertSame('Sprint deleted. Stories returned to backlog.', $_SESSION['flash_message']);
    }

    public function testDeleteWithDifferentProject(): void
    {
        $sprintOtherProject = ['id' => 1, 'project_id' => 999, 'name' => 'Sprint', 'team_capacity' => 20];
        $this->db->method('query')->willReturnOnConsecutiveCalls(
            $this->stmt($sprintOtherProject),
            $this->stmt($this->project)
        );
        $this->ctrl()->delete(1);
        $this->assertSame('/app/sprints?project_id=999', $this->response->redirectedTo);
        // Verify access denied for different project's sprint
        $this->assertArrayHasKey('flash_error', $_SESSION);
    }

    // ===== assignStory() =====

    public function testAssignStoryMissingSprint(): void
    {
        $req = new FakeRequest('POST', '/', [], [], '127.0.0.1', [], json_encode(['sprint_id' => 0, 'story_id' => 1]));
        $this->ctrl($req)->assignStory();
        $this->assertSame(400, $this->response->jsonStatus);
    }

    public function testAssignStoryMissingStory(): void
    {
        $req = new FakeRequest('POST', '/', [], [], '127.0.0.1', [], json_encode(['sprint_id' => 1, 'story_id' => 0]));
        $this->ctrl($req)->assignStory();
        $this->assertSame(400, $this->response->jsonStatus);
    }

    public function testAssignStorySprintNotFound(): void
    {
        $this->db->method('query')->willReturn($this->stmt(null));
        $req = new FakeRequest('POST', '/', [], [], '127.0.0.1', [], json_encode(['sprint_id' => 99, 'story_id' => 1]));
        $this->ctrl($req)->assignStory();
        $this->assertSame(404, $this->response->jsonStatus);
    }

    public function testAssignStoryNotFound(): void
    {
        $this->db->method('query')->willReturnOnConsecutiveCalls(
            $this->stmt($this->sprint),
            $this->stmt(null)
        );
        $req = new FakeRequest('POST', '/', [], [], '127.0.0.1', [], json_encode(['sprint_id' => 1, 'story_id' => 99]));
        $this->ctrl($req)->assignStory();
        $this->assertSame(404, $this->response->jsonStatus);
    }

    public function testAssignStoryProjectMismatch(): void
    {
        $wrongStory = ['id' => 1, 'project_id' => 999];
        $this->db->method('query')->willReturnOnConsecutiveCalls(
            $this->stmt($this->sprint),
            $this->stmt($wrongStory)
        );
        $req = new FakeRequest('POST', '/', [], [], '127.0.0.1', [], json_encode(['sprint_id' => 1, 'story_id' => 1]));
        $this->ctrl($req)->assignStory();
        $this->assertSame(404, $this->response->jsonStatus);
    }

    public function testAssignStoryAccessDenied(): void
    {
        $this->db->method('query')->willReturnOnConsecutiveCalls(
            $this->stmt($this->sprint),
            $this->stmt($this->story),
            $this->stmt(null)
        );
        $req = new FakeRequest('POST', '/', [], [], '127.0.0.1', [], json_encode(['sprint_id' => 1, 'story_id' => 1]));
        $this->ctrl($req)->assignStory();
        $this->assertSame(403, $this->response->jsonStatus);
    }

    public function testAssignStorySuccess(): void
    {
        $this->db->method('query')->willReturnOnConsecutiveCalls(
            $this->stmt($this->sprint),
            $this->stmt($this->story),
            $this->stmt($this->project),
            $this->stmt(null),
            $this->stmt(null),
            $this->stmt(null, [])
        );
        $req = new FakeRequest('POST', '/', [], [], '127.0.0.1', [], json_encode(['sprint_id' => 1, 'story_id' => 1]));
        $this->ctrl($req)->assignStory();
        $this->assertSame(200, $this->response->jsonStatus);
        $this->assertSame('ok', $this->response->jsonPayload['status']);
        $this->assertArrayHasKey('sprint_load', $this->response->jsonPayload);
    }

    public function testAssignStoryWithExistingAssignment(): void
    {
        $existingSprint = ['id' => 99, 'project_id' => 5];
        $this->db->method('query')->willReturnOnConsecutiveCalls(
            $this->stmt($this->sprint),
            $this->stmt($this->story),
            $this->stmt($this->project),
            $this->stmt($existingSprint),
            $this->stmt(null),
            $this->stmt(null),
            $this->stmt(null, [])
        );
        $req = new FakeRequest('POST', '/', [], [], '127.0.0.1', [], json_encode(['sprint_id' => 1, 'story_id' => 1]));
        $this->ctrl($req)->assignStory();
        $this->assertSame(200, $this->response->jsonStatus);
    }

    // ===== unassignStory() =====

    public function testUnassignStoryMissingSprint(): void
    {
        $req = new FakeRequest('POST', '/', [], [], '127.0.0.1', [], json_encode(['sprint_id' => 0, 'story_id' => 1]));
        $this->ctrl($req)->unassignStory();
        $this->assertSame(400, $this->response->jsonStatus);
    }

    public function testUnassignStoryMissingStory(): void
    {
        $req = new FakeRequest('POST', '/', [], [], '127.0.0.1', [], json_encode(['sprint_id' => 1, 'story_id' => 0]));
        $this->ctrl($req)->unassignStory();
        $this->assertSame(400, $this->response->jsonStatus);
    }

    public function testUnassignStorySprintNotFound(): void
    {
        $this->db->method('query')->willReturn($this->stmt(null));
        $req = new FakeRequest('POST', '/', [], [], '127.0.0.1', [], json_encode(['sprint_id' => 99, 'story_id' => 1]));
        $this->ctrl($req)->unassignStory();
        $this->assertSame(404, $this->response->jsonStatus);
    }

    public function testUnassignStoryAccessDenied(): void
    {
        $this->db->method('query')->willReturnOnConsecutiveCalls(
            $this->stmt($this->sprint),
            $this->stmt(null)
        );
        $req = new FakeRequest('POST', '/', [], [], '127.0.0.1', [], json_encode(['sprint_id' => 1, 'story_id' => 1]));
        $this->ctrl($req)->unassignStory();
        $this->assertSame(403, $this->response->jsonStatus);
    }

    public function testUnassignStorySuccess(): void
    {
        $this->db->method('query')->willReturnOnConsecutiveCalls(
            $this->stmt($this->sprint),
            $this->stmt($this->project),
            $this->stmt(null),
            $this->stmt(null, [])
        );
        $req = new FakeRequest('POST', '/', [], [], '127.0.0.1', [], json_encode(['sprint_id' => 1, 'story_id' => 1]));
        $this->ctrl($req)->unassignStory();
        $this->assertSame(200, $this->response->jsonStatus);
        $this->assertSame('ok', $this->response->jsonPayload['status']);
    }

    // ===== aiAllocate() =====

    public function testAiAllocateProjectNotFound(): void
    {
        $this->db->method('query')->willReturn($this->stmt(null));
        $req = $this->makePostRequest(['project_id' => '5']);
        $this->ctrl($req)->aiAllocate();
        $this->assertSame('/app/home', $this->response->redirectedTo);
    }

    public function testAiAllocateNoSprints(): void
    {
        $this->db->method('query')->willReturnOnConsecutiveCalls(
            $this->stmt($this->project),
            $this->stmt(null, []),
            $this->stmt(null, [])
        );
        $req = $this->makePostRequest(['project_id' => '5']);
        $this->ctrl($req)->aiAllocate();
        $this->assertSame('/app/sprints?project_id=5', $this->response->redirectedTo);
        $this->assertSame('Create at least one sprint before auto-allocating.', $_SESSION['flash_error']);
    }

    public function testAiAllocateNoUnallocatedStories(): void
    {
        $this->db->method('query')->willReturnOnConsecutiveCalls(
            $this->stmt($this->project),
            $this->stmt(null, [$this->sprint]),
            $this->stmt(null, [])
        );
        $req = $this->makePostRequest(['project_id' => '5']);
        $this->ctrl($req)->aiAllocate();
        $this->assertSame('/app/sprints?project_id=5', $this->response->redirectedTo);
        $this->assertSame('No unallocated stories to assign.', $_SESSION['flash_error']);
    }

    // ===== autoGenerate() =====

    public function testAutoGenerateProjectNotFound(): void
    {
        $this->db->method('query')->willReturn($this->stmt(null));
        $req = $this->makePostRequest(['project_id' => '5']);
        $this->ctrl($req)->autoGenerate();
        $this->assertSame('/app/home', $this->response->redirectedTo);
    }

    public function testAutoGenerateMissingStartDate(): void
    {
        $this->db->method('query')->willReturn($this->stmt($this->project));
        $req = $this->makePostRequest(['project_id' => '5', 'start_date' => '', 'capacity' => '20', 'num_sprints' => '2']);
        $this->ctrl($req)->autoGenerate();
        $this->assertSame('/app/sprints?project_id=5', $this->response->redirectedTo);
        $this->assertStringContainsString('start date', $_SESSION['flash_error']);
    }

    public function testAutoGenerateZeroCapacity(): void
    {
        $this->db->method('query')->willReturn($this->stmt($this->project));
        $req = $this->makePostRequest(['project_id' => '5', 'start_date' => '2025-01-01', 'capacity' => '0', 'num_sprints' => '2']);
        $this->ctrl($req)->autoGenerate();
        $this->assertStringContainsString('capacity', $_SESSION['flash_error']);
    }

    public function testAutoGenerateZeroSprints(): void
    {
        $this->db->method('query')->willReturn($this->stmt($this->project));
        $req = $this->makePostRequest(['project_id' => '5', 'start_date' => '2025-01-01', 'capacity' => '20', 'num_sprints' => '0']);
        $this->ctrl($req)->autoGenerate();
        $this->assertStringContainsString('start date', $_SESSION['flash_error']);
    }

    public function testAutoGenerateSuccess(): void
    {
        $this->db->method('query')->willReturnOnConsecutiveCalls(
            $this->stmt($this->project),
            $this->stmt(null, []),
            $this->stmt(null),
            $this->stmt(null)
        );
        $req = $this->makePostRequest(['project_id' => '5', 'start_date' => '2025-01-01', 'sprint_length' => '14', 'capacity' => '20', 'num_sprints' => '2']);
        $this->ctrl($req)->autoGenerate();
        $this->assertSame('/app/sprints?project_id=5', $this->response->redirectedTo);
        $this->assertStringContainsString('2 sprints created', $_SESSION['flash_message']);
    }

    // ===== autoFill() =====

    public function testAutoFillProjectNotFound(): void
    {
        $this->db->method('query')->willReturn($this->stmt(null));
        $req = $this->makePostRequest(['project_id' => '5']);
        $this->ctrl($req)->autoFill();
        $this->assertSame('/app/home', $this->response->redirectedTo);
    }

    public function testAutoFillNoSprints(): void
    {
        $this->db->method('query')->willReturnOnConsecutiveCalls(
            $this->stmt($this->project),
            $this->stmt(null, []),
            $this->stmt(null, [])
        );
        $req = $this->makePostRequest(['project_id' => '5']);
        $this->ctrl($req)->autoFill();
        $this->assertSame('/app/sprints?project_id=5', $this->response->redirectedTo);
        $this->assertStringContainsString('Need both sprints', $_SESSION['flash_error']);
    }

    public function testAutoFillNoUnallocated(): void
    {
        $this->db->method('query')->willReturnOnConsecutiveCalls(
            $this->stmt($this->project),
            $this->stmt(null, [$this->sprint]),
            $this->stmt(null, [])
        );
        $req = $this->makePostRequest(['project_id' => '5']);
        $this->ctrl($req)->autoFill();
        $this->assertSame('/app/sprints?project_id=5', $this->response->redirectedTo);
        $this->assertStringContainsString('Need both sprints', $_SESSION['flash_error']);
    }

    public function testAutoFillSuccess(): void
    {
        $story1 = ['id' => 1, 'project_id' => 5, 'size' => 5, 'priority_number' => 1];
        $story2 = ['id' => 2, 'project_id' => 5, 'size' => 8, 'priority_number' => 2];
        $this->db->method('query')->willReturnOnConsecutiveCalls(
            $this->stmt($this->project),
            $this->stmt(null, [$this->sprint]),
            $this->stmt(null, [$story1, $story2]),
            $this->stmt(null, [0]),
            $this->stmt(null),
            $this->stmt(null)
        );
        $req = $this->makePostRequest(['project_id' => '5']);
        $this->ctrl($req)->autoFill();
        $this->assertSame('/app/sprints?project_id=5', $this->response->redirectedTo);
        $this->assertStringContainsString('stories allocated', $_SESSION['flash_message']);
    }

    public function testAutoFillPartialFill(): void
    {
        $story1 = ['id' => 1, 'project_id' => 5, 'size' => 15, 'priority_number' => 1];
        $story2 = ['id' => 2, 'project_id' => 5, 'size' => 10, 'priority_number' => 2];
        $this->db->method('query')->willReturnOnConsecutiveCalls(
            $this->stmt($this->project),
            $this->stmt(null, [$this->sprint]),
            $this->stmt(null, [$story1, $story2]),
            $this->stmt(null, [0]),
            $this->stmt(null)
        );
        $req = $this->makePostRequest(['project_id' => '5']);
        $this->ctrl($req)->autoFill();
        $this->assertStringContainsString("didn't fit", $_SESSION['flash_message']);
    }
}
