<?php

declare(strict_types=1);

namespace StratFlow\Tests\Support;

use PHPUnit\Framework\TestCase;
use StratFlow\Core\Database;

/**
 * DatabaseTestCase
 *
 * Base class for integration tests that need a real database connection.
 * Wraps each test in a transaction that is rolled back in tearDown(),
 * keeping the test database clean without per-test DELETE statements.
 *
 * Usage: extend this class instead of PHPUnit\Framework\TestCase.
 * Call $this->db inside your test; the connection is already open
 * and a transaction has been started.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected Database $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new Database(getTestDbConfig());
        $this->db->getPdo()->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->db->getPdo()->inTransaction()) {
            $this->db->getPdo()->rollBack();
        }
        parent::tearDown();
    }
}
