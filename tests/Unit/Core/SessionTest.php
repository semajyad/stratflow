<?php

declare(strict_types=1);

namespace StratFlow\Tests\Unit\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use StratFlow\Core\Session;

/**
 * SessionTest
 *
 * Tests the Session wrapper around $_SESSION.
 *
 * Uses a partial mock with disabled constructor to avoid session_start()
 * calls in CLI tests. Tests focus on the public API: get, set, has, remove,
 * destroy, flash, and getFlash.
 */
class SessionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    private function makeSession(): Session
    {
        return $this->getMockBuilder(Session::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
    }

    // ===========================
    // get()
    // ===========================

    #[Test]
    public function testGetReturnsSessionValue(): void
    {
        $session = $this->makeSession();
        $_SESSION['foo'] = 'bar';

        $this->assertSame('bar', $session->get('foo'));
    }

    #[Test]
    public function testGetReturnsDefaultWhenKeyMissing(): void
    {
        $session = $this->makeSession();

        $this->assertNull($session->get('missing'));
        $this->assertSame('fallback', $session->get('missing', 'fallback'));
    }

    #[Test]
    public function testGetReturnsZeroAsValue(): void
    {
        $session = $this->makeSession();
        $_SESSION['count'] = 0;

        $this->assertSame(0, $session->get('count'));
        $this->assertFalse($session->get('count') === null);
    }

    #[Test]
    public function testGetReturnsEmptyStringAsValue(): void
    {
        $session = $this->makeSession();
        $_SESSION['empty'] = '';

        $this->assertSame('', $session->get('empty'));
    }

    #[Test]
    public function testGetReturnsArrayValue(): void
    {
        $session = $this->makeSession();
        $_SESSION['data'] = ['x' => 1, 'y' => 2];

        $this->assertSame(['x' => 1, 'y' => 2], $session->get('data'));
    }

    // ===========================
    // set()
    // ===========================

    #[Test]
    public function testSetWritesSessionValue(): void
    {
        $session = $this->makeSession();
        $session->set('name', 'Alice');

        $this->assertSame('Alice', $_SESSION['name']);
    }

    #[Test]
    public function testSetOverwritesExistingValue(): void
    {
        $session = $this->makeSession();
        $_SESSION['key'] = 'old';
        $session->set('key', 'new');

        $this->assertSame('new', $_SESSION['key']);
    }

    #[Test]
    public function testSetStoresNull(): void
    {
        $session = $this->makeSession();
        $session->set('nullable', null);

        $this->assertArrayHasKey('nullable', $_SESSION);
        $this->assertNull($_SESSION['nullable']);
    }

    #[Test]
    public function testSetStoresArray(): void
    {
        $session = $this->makeSession();
        $session->set('config', ['db' => 'localhost', 'port' => 5432]);

        $this->assertSame(['db' => 'localhost', 'port' => 5432], $_SESSION['config']);
    }

    #[Test]
    public function testSetStoresObject(): void
    {
        $session = $this->makeSession();
        $obj = new \stdClass();
        $obj->id = 42;
        $session->set('obj', $obj);

        $this->assertSame($obj, $_SESSION['obj']);
        $this->assertSame(42, $_SESSION['obj']->id);
    }

    // ===========================
    // has()
    // ===========================

    #[Test]
    public function testHasReturnsTrueWhenKeyExists(): void
    {
        $session = $this->makeSession();
        $_SESSION['present'] = 'value';

        $this->assertTrue($session->has('present'));
    }

    #[Test]
    public function testHasReturnsFalseWhenKeyMissing(): void
    {
        $session = $this->makeSession();

        $this->assertFalse($session->has('missing'));
    }

    #[Test]
    public function testHasReturnsFalseWhenValueIsNull(): void
    {
        $session = $this->makeSession();
        $_SESSION['nullable'] = null;

        // isset() returns false for null values, so has() returns false
        $this->assertFalse($session->has('nullable'));
    }

    #[Test]
    public function testHasReturnsTrueWhenValueIsFalse(): void
    {
        $session = $this->makeSession();
        $_SESSION['flag'] = false;

        $this->assertTrue($session->has('flag'));
    }

    #[Test]
    public function testHasReturnsTrueWhenValueIsZero(): void
    {
        $session = $this->makeSession();
        $_SESSION['count'] = 0;

        $this->assertTrue($session->has('count'));
    }

    // ===========================
    // remove()
    // ===========================

    #[Test]
    public function testRemoveDeletesSessionKey(): void
    {
        $session = $this->makeSession();
        $_SESSION['foo'] = 'bar';
        $session->remove('foo');

        $this->assertFalse(isset($_SESSION['foo']));
    }

    #[Test]
    public function testRemoveDoesNothingWhenKeyMissing(): void
    {
        $session = $this->makeSession();
        $_SESSION['other'] = 'value';

        $session->remove('missing');

        $this->assertSame('value', $_SESSION['other']);
    }

    #[Test]
    public function testRemoveDeletesWithoutAffectingOtherKeys(): void
    {
        $session = $this->makeSession();
        $_SESSION['a'] = 1;
        $_SESSION['b'] = 2;
        $_SESSION['c'] = 3;
        $session->remove('b');

        $this->assertSame(1, $_SESSION['a']);
        $this->assertFalse(isset($_SESSION['b']));
        $this->assertSame(3, $_SESSION['c']);
    }

    // ===========================
    // destroy()
    // ===========================

    #[Test]
    public function testDestroyEmptiesSessionArray(): void
    {
        $session = $this->makeSession();
        $_SESSION['user_id'] = 1;
        $_SESSION['token'] = 'abc123';

        $session->destroy();

        $this->assertSame([], $_SESSION);
    }

    #[Test]
    public function testDestroyEmptiesSingleEntry(): void
    {
        $session = $this->makeSession();
        $_SESSION['auth'] = true;

        $session->destroy();

        $this->assertFalse(isset($_SESSION['auth']));
    }

    // ===========================
    // flash() and getFlash()
    // ===========================

    #[Test]
    public function testFlashStoresMessage(): void
    {
        $session = $this->makeSession();
        $session->flash('success', 'Operation completed');

        $this->assertSame('Operation completed', $_SESSION['_flash']['success']);
    }

    #[Test]
    public function testGetFlashRetrievesMessage(): void
    {
        $session = $this->makeSession();
        $_SESSION['_flash']['alert'] = 'Warning: timeout';

        $this->assertSame('Warning: timeout', $session->getFlash('alert'));
    }

    #[Test]
    public function testGetFlashDeletesMessageAfterRetrieval(): void
    {
        $session = $this->makeSession();
        $_SESSION['_flash']['info'] = 'Hello';
        $session->getFlash('info');

        $this->assertFalse(isset($_SESSION['_flash']['info']));
    }

    #[Test]
    public function testGetFlashReturnsNullWhenMissing(): void
    {
        $session = $this->makeSession();

        $this->assertNull($session->getFlash('missing'));
    }

    #[Test]
    public function testGetFlashDoesNotDeleteWhenMissing(): void
    {
        $session = $this->makeSession();
        $_SESSION['_flash']['other'] = 'data';

        $session->getFlash('missing');

        $this->assertSame('data', $_SESSION['_flash']['other']);
    }

    #[Test]
    public function testFlashAndGetFlashTogether(): void
    {
        $session = $this->makeSession();

        $session->flash('message', 'Created successfully');
        $msg = $session->getFlash('message');

        $this->assertSame('Created successfully', $msg);
        $this->assertFalse(isset($_SESSION['_flash']['message']));
    }

    #[Test]
    public function testMultipleFlashMessagesIndependent(): void
    {
        $session = $this->makeSession();

        $session->flash('success', 'All good');
        $session->flash('error', 'Something failed');

        $this->assertSame('All good', $session->getFlash('success'));
        $this->assertSame('Something failed', $_SESSION['_flash']['error']);
    }

    #[Test]
    public function testFlashWithEmptyMessage(): void
    {
        $session = $this->makeSession();
        $session->flash('note', '');

        $this->assertSame('', $_SESSION['_flash']['note']);
        $this->assertSame('', $session->getFlash('note'));
    }

    #[Test]
    public function testFlashOverwritesPreviousMessage(): void
    {
        $session = $this->makeSession();
        $session->flash('status', 'First');
        $session->flash('status', 'Second');

        $this->assertSame('Second', $_SESSION['_flash']['status']);
    }

    #[Test]
    public function testDestroyIsSafeWhenSessionNotStarted(): void
    {
        $session = $this->makeSession();
        // Must not emit "Trying to destroy uninitialized session"
        $session->destroy();
        $this->assertTrue(true);
    }
}
