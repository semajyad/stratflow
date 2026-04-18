<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Models;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Database;
use StratFlow\Models\StoryQualityConfig;

#[CoversClass(StoryQualityConfig::class)]
class StoryQualityConfigTest extends TestCase
{
    private Database $db;
    private \PDOStatement $stmt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db   = $this->createMock(Database::class);
        $this->stmt = $this->createMock(\PDOStatement::class);
        $this->db->method('query')->willReturn($this->stmt);
    }

    #[Test]
    public function findByOrgIdReturnsFetchAllResult(): void
    {
        $rows = [
            ['id' => 1, 'org_id' => 1, 'rule_type' => 'splitting_pattern', 'label' => 'SPIDR'],
            ['id' => 2, 'org_id' => 1, 'rule_type' => 'mandatory_condition', 'label' => 'Must have AC'],
        ];
        $this->stmt->method('fetchAll')->willReturn($rows);

        $result = StoryQualityConfig::findByOrgId($this->db, 1);

        $this->assertSame($rows, $result);
    }

    #[Test]
    public function createInsertsAndReturnsId(): void
    {
        $this->db->method('lastInsertId')->willReturn('5');
        $this->db->expects($this->once())->method('query');

        $data = ['org_id' => 1, 'rule_type' => 'splitting_pattern', 'label' => 'Custom Pattern'];

        $id = StoryQualityConfig::create($this->db, $data);

        $this->assertSame(5, $id);
    }

    #[Test]
    public function deleteCallsQueryOnce(): void
    {
        $this->db->expects($this->once())->method('query');

        StoryQualityConfig::delete($this->db, 3, 1);
    }

    #[Test]
    public function seedDefaultsSkipsWhenDefaultsAlreadyExist(): void
    {
        $this->stmt->method('fetch')->willReturn(['cnt' => 5]);
        // Should only call once (the COUNT query), not INSERT
        $this->db->expects($this->once())->method('query');

        StoryQualityConfig::seedDefaults($this->db, 1);
    }

    #[Test]
    public function seedDefaultsInsertsWhenNoneExist(): void
    {
        $this->stmt->method('fetch')->willReturn(['cnt' => 0]);
        // One SELECT + 5 INSERTs for the 5 default patterns
        $this->db->expects($this->exactly(6))->method('query');

        StoryQualityConfig::seedDefaults($this->db, 2);
    }

    #[Test]
    public function buildPromptBlockReturnsEmptyStringWhenNoRows(): void
    {
        $this->stmt->method('fetchAll')->willReturn([]);

        $result = StoryQualityConfig::buildPromptBlock($this->db, 1);

        $this->assertSame('', $result);
    }

    #[Test]
    public function buildPromptBlockContainsSplittingPatternLabels(): void
    {
        $rows = [
            ['rule_type' => 'splitting_pattern', 'label' => 'SPIDR', 'id' => 1, 'is_active' => 1, 'display_order' => 1],
            ['rule_type' => 'splitting_pattern', 'label' => 'Happy/Unhappy Path', 'id' => 2, 'is_active' => 1, 'display_order' => 2],
        ];
        $this->stmt->method('fetchAll')->willReturn($rows);

        $result = StoryQualityConfig::buildPromptBlock($this->db, 1);

        $this->assertStringContainsString('SPIDR', $result);
        $this->assertStringContainsString('Happy/Unhappy Path', $result);
    }

    #[Test]
    public function buildPromptBlockContainsMandatoryConditions(): void
    {
        $rows = [
            ['rule_type' => 'splitting_pattern', 'label' => 'SPIDR', 'id' => 1, 'is_active' => 1, 'display_order' => 1],
            ['rule_type' => 'mandatory_condition', 'label' => 'All stories need AC', 'id' => 2, 'is_active' => 1, 'display_order' => 1],
        ];
        $this->stmt->method('fetchAll')->willReturn($rows);

        $result = StoryQualityConfig::buildPromptBlock($this->db, 1);

        $this->assertStringContainsString('All stories need AC', $result);
        $this->assertStringContainsString('Mandatory conditions', $result);
    }
}
