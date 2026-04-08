<?php

declare(strict_types=1);

namespace StratFlow\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Auth;
use StratFlow\Core\Database;
use StratFlow\Core\Response;
use StratFlow\Core\Session;
use StratFlow\Middleware\AdminMiddleware;

/**
 * AdminAccessTest
 *
 * Integration tests verifying that AdminMiddleware correctly grants or blocks
 * access based on the authenticated user's role.
 *
 * Creates a real regular user and a real org_admin user in MySQL; tears down
 * after each test. The Response is mocked to capture redirect calls without
 * triggering real HTTP headers.
 */
class AdminAccessTest extends TestCase
{
    // ===========================
    // SETUP / TEARDOWN
    // ===========================

    private Database $db;
    private int $orgId;
    private int $regularUserId;
    private int $adminUserId;

    protected function setUp(): void
    {
        $this->db = new Database(getTestDbConfig());

        // Clean up any leftover data from a previous failed run
        $this->db->query(
            "DELETE u FROM users u
             JOIN organisations o ON u.org_id = o.id
             WHERE o.name = 'Test Org - AdminAccessTest'"
        );
        $this->db->query("DELETE FROM organisations WHERE name = 'Test Org - AdminAccessTest'");

        // Create a test organisation
        $this->db->query("INSERT INTO organisations (name) VALUES (?)", ['Test Org - AdminAccessTest']);
        $this->orgId = (int) $this->db->lastInsertId();

        // Create a regular (non-admin) user
        $this->db->query(
            "INSERT INTO users (org_id, email, password_hash, full_name, role)
             VALUES (?, ?, ?, ?, ?)",
            [$this->orgId, 'regular_admin_test@test.invalid', password_hash('pass', PASSWORD_DEFAULT), 'Regular User', 'user']
        );
        $this->regularUserId = (int) $this->db->lastInsertId();

        // Create an org_admin user
        $this->db->query(
            "INSERT INTO users (org_id, email, password_hash, full_name, role)
             VALUES (?, ?, ?, ?, ?)",
            [$this->orgId, 'orgadmin_admin_test@test.invalid', password_hash('pass', PASSWORD_DEFAULT), 'Admin User', 'org_admin']
        );
        $this->adminUserId = (int) $this->db->lastInsertId();
    }

    protected function tearDown(): void
    {
        $this->db->query("DELETE FROM users WHERE org_id = ?", [$this->orgId]);
        $this->db->query("DELETE FROM organisations WHERE id = ?", [$this->orgId]);
    }

    // ===========================
    // HELPERS
    // ===========================

    /**
     * Build a mock Auth that returns the given user array from user().
     *
     * @param array|null $user The user row to return, or null for unauthenticated
     * @return Auth
     */
    private function makeAuthWithUser(?array $user): Auth
    {
        $auth = $this->getMockBuilder(Auth::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['user'])
            ->getMock();

        $auth->method('user')->willReturn($user);

        return $auth;
    }

    /**
     * Build a mock Response that records any redirect target without sending real headers.
     *
     * @param string|null &$redirectedTo Variable to capture the redirect URL (passed by ref)
     * @return Response
     */
    private function makeResponseCapturingRedirect(?string &$redirectedTo): Response
    {
        $response = $this->getMockBuilder(Response::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['redirect'])
            ->getMock();

        $response->method('redirect')
            ->willReturnCallback(function (string $url) use (&$redirectedTo): void {
                $redirectedTo = $url;
            });

        return $response;
    }

    // ===========================
    // MIDDLEWARE BEHAVIOUR
    // ===========================

    #[Test]
    public function testRegularUserIsBlockedFromAdminRoutes(): void
    {
        $redirectedTo = null;

        $auth     = $this->makeAuthWithUser(['role' => 'user', 'id' => $this->regularUserId]);
        $response = $this->makeResponseCapturingRedirect($redirectedTo);
        $middleware = new AdminMiddleware();

        $result = $middleware->handle($auth, $response);

        $this->assertFalse($result);
        $this->assertSame('/app/home', $redirectedTo);
    }

    #[Test]
    public function testOrgAdminCanPassAdminMiddleware(): void
    {
        $redirectedTo = null;

        $auth     = $this->makeAuthWithUser(['role' => 'org_admin', 'id' => $this->adminUserId]);
        $response = $this->makeResponseCapturingRedirect($redirectedTo);
        $middleware = new AdminMiddleware();

        $result = $middleware->handle($auth, $response);

        $this->assertTrue($result);
        $this->assertNull($redirectedTo); // no redirect was issued
    }

    #[Test]
    public function testUnauthenticatedUserIsBlockedFromAdminRoutes(): void
    {
        $redirectedTo = null;

        $auth     = $this->makeAuthWithUser(null); // no logged-in user
        $response = $this->makeResponseCapturingRedirect($redirectedTo);
        $middleware = new AdminMiddleware();

        $result = $middleware->handle($auth, $response);

        $this->assertFalse($result);
        $this->assertSame('/app/home', $redirectedTo);
    }
}
