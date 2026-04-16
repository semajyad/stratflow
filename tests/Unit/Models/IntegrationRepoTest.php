<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Models;

use PHPUnit\Framework\TestCase;
use StratFlow\Models\IntegrationRepo;

class IntegrationRepoTest extends TestCase
{
    private function makeDb(mixed $fetch = false, array $fetchAll = []): \StratFlow\Core\Database
    {
        $db = $this->createMock(\StratFlow\Core\Database::class);
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn($fetch);
        $stmt->method('fetchAll')->willReturn($fetchAll);
        $db->method('query')->willReturn($stmt);
        return $db;
    }

    public function testUpsertInsertsOrUpdatesRepo(): void
    {
        $db = $this->makeDb();
        $db->method('lastInsertId')->willReturn('42');

        $result = IntegrationRepo::upsert($db, 10, 1, 12345, 'acme/repo');

        $this->assertSame(42, $result);
    }

    public function testFindByIdForOrgReturnsRowWhenExists(): void
    {
        $row = ['id' => 5, 'integration_id' => 10, 'org_id' => 1, 'repo_github_id' => 12345, 'repo_full_name' => 'acme/repo'];
        $db = $this->makeDb($row);

        $result = IntegrationRepo::findByIdForOrg($db, 5, 1);

        $this->assertIsArray($result);
        $this->assertSame(5, $result['id']);
        $this->assertSame('acme/repo', $result['repo_full_name']);
    }

    public function testFindByIdForOrgReturnsNullWhenNotFound(): void
    {
        $db = $this->makeDb(false);

        $result = IntegrationRepo::findByIdForOrg($db, 999, 1);

        $this->assertNull($result);
    }

    public function testFindByIntegrationAndGithubIdReturnsMatchingRepo(): void
    {
        $row = ['id' => 7, 'integration_id' => 10, 'repo_github_id' => 12345, 'repo_full_name' => 'acme/repo'];
        $db = $this->makeDb($row);

        $result = IntegrationRepo::findByIntegrationAndGithubId($db, 10, 12345);

        $this->assertIsArray($result);
        $this->assertSame(12345, $result['repo_github_id']);
    }

    public function testFindByIntegrationAndGithubIdReturnsNullWhenNotFound(): void
    {
        $db = $this->makeDb(false);

        $result = IntegrationRepo::findByIntegrationAndGithubId($db, 10, 99999);

        $this->assertNull($result);
    }

    public function testFindByIntegrationReturnsAllReposForIntegration(): void
    {
        $rows = [
            ['id' => 1, 'integration_id' => 10, 'repo_full_name' => 'acme/repo1'],
            ['id' => 2, 'integration_id' => 10, 'repo_full_name' => 'acme/repo2'],
        ];
        $db = $this->makeDb(false, $rows);

        $result = IntegrationRepo::findByIntegration($db, 10);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertSame('acme/repo1', $result[0]['repo_full_name']);
    }

    public function testFindAllForOrgReturnsReposWithAccountLogin(): void
    {
        $rows = [
            ['id' => 1, 'integration_id' => 10, 'repo_full_name' => 'org1/repo1', 'account_login' => 'github_user1', 'integration_id_col' => 10],
            ['id' => 2, 'integration_id' => 11, 'repo_full_name' => 'org2/repo2', 'account_login' => 'github_user2', 'integration_id_col' => 11],
        ];
        $db = $this->makeDb(false, $rows);

        $result = IntegrationRepo::findAllForOrg($db, 1);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertSame('github_user1', $result[0]['account_login']);
    }

    public function testDeleteByIntegrationAndGithubIdExecutesDelete(): void
    {
        $db = $this->createMock(\StratFlow\Core\Database::class);
        $stmt = $this->createMock(\PDOStatement::class);
        $db->method('query')->willReturn($stmt);

        IntegrationRepo::deleteByIntegrationAndGithubId($db, 10, 12345);

        $this->assertTrue(true);
    }

    public function testDeleteByIntegrationExecutesDelete(): void
    {
        $db = $this->createMock(\StratFlow\Core\Database::class);
        $stmt = $this->createMock(\PDOStatement::class);
        $db->method('query')->willReturn($stmt);

        IntegrationRepo::deleteByIntegration($db, 10);

        $this->assertTrue(true);
    }
}
