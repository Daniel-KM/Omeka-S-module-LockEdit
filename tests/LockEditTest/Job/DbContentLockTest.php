<?php declare(strict_types=1);

namespace LockEditTest\Job;

use CommonTest\AbstractHttpControllerTestCase;
use DateTime;
use LockEdit\Job\DbContentLock;
use LockEditTest\LockEditTestTrait;

/**
 * Tests for the DbContentLock job.
 *
 * Note: This test requires EasyAdmin module to be installed since
 * DbContentLock extends EasyAdmin\Job\AbstractCheck.
 */
class DbContentLockTest extends AbstractHttpControllerTestCase
{
    use LockEditTestTrait;

    /**
     * @var bool
     */
    protected static bool $hasEasyAdmin;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Check if EasyAdmin is available.
        self::$hasEasyAdmin = class_exists(\EasyAdmin\Job\AbstractCheck::class);
    }

    public function setUp(): void
    {
        if (!self::$hasEasyAdmin) {
            $this->markTestSkipped('EasyAdmin module is required for DbContentLock tests.');
        }

        parent::setUp();
        $this->loginAdmin();
        $this->cleanContentLocks();
    }

    public function tearDown(): void
    {
        $this->cleanContentLocks();
        $this->cleanupResources();
        parent::tearDown();
    }

    // =========================================================================
    // Tests: Check Process (no removal)
    // =========================================================================

    public function testCheckProcessCountsLocks(): void
    {
        // Create some locks with different ages.
        $this->createContentLock(1, 'items', null, new DateTime('-1 hour'));
        $this->createContentLock(2, 'items', null, new DateTime('-5 hours'));
        $this->createContentLock(3, 'items', null, new DateTime('-10 hours'));

        $this->assertSame(3, $this->countContentLocks());

        // Run the check job (not the clean job).
        $args = [
            'process' => 'lockedit_db_content_lock_check',
            'hours' => 3,
        ];

        $this->runJob(DbContentLock::class, $args);

        // All locks should still exist (check doesn't remove).
        $this->assertSame(3, $this->countContentLocks());
    }

    // =========================================================================
    // Tests: Clean Process (with removal)
    // =========================================================================

    public function testCleanProcessRemovesOldLocks(): void
    {
        // Create locks with different ages.
        $this->createContentLock(1, 'items', null, new DateTime('-1 hour'));
        $this->createContentLock(2, 'items', null, new DateTime('-5 hours'));
        $this->createContentLock(3, 'items', null, new DateTime('-10 hours'));

        $this->assertSame(3, $this->countContentLocks());

        // Run the clean job for locks older than 3 hours.
        $args = [
            'process' => 'lockedit_db_content_lock_clean',
            'hours' => 3,
        ];

        $this->runJob(DbContentLock::class, $args);

        // Only the 1-hour lock should remain.
        $this->assertSame(1, $this->countContentLocks());
    }

    public function testCleanProcessRemovesAllLocksWhenHoursIsZero(): void
    {
        // Create some locks.
        $this->createContentLock(1, 'items', null, new DateTime('-1 minute'));
        $this->createContentLock(2, 'items', null, new DateTime('-5 hours'));

        $this->assertSame(2, $this->countContentLocks());

        // Run the clean job with hours = 0.
        $args = [
            'process' => 'lockedit_db_content_lock_clean',
            'hours' => 0,
        ];

        $this->runJob(DbContentLock::class, $args);

        // All locks should be removed.
        $this->assertSame(0, $this->countContentLocks());
    }

    public function testCleanProcessFiltersLocksByUser(): void
    {
        $secondUser = $this->createSecondUser();
        $adminUser = $this->getCurrentUser();

        // Create locks by different users.
        $this->createContentLock(1, 'items', $adminUser, new DateTime('-5 hours'));
        $this->createContentLock(2, 'items', $secondUser, new DateTime('-5 hours'));
        $this->createContentLock(3, 'items', $secondUser, new DateTime('-5 hours'));

        $this->assertSame(3, $this->countContentLocks());

        // Run the clean job only for the second user.
        $args = [
            'process' => 'lockedit_db_content_lock_clean',
            'hours' => 1,
            'user_id' => [$secondUser->getId()],
        ];

        $this->runJob(DbContentLock::class, $args);

        // Only admin's lock should remain.
        $this->assertSame(1, $this->countContentLocks());
    }

    public function testCleanProcessFiltersByHoursAndUser(): void
    {
        $secondUser = $this->createSecondUser();
        $adminUser = $this->getCurrentUser();

        // Create locks.
        $this->createContentLock(1, 'items', $adminUser, new DateTime('-1 hour'));
        $this->createContentLock(2, 'items', $secondUser, new DateTime('-1 hour'));
        $this->createContentLock(3, 'items', $secondUser, new DateTime('-5 hours'));
        $this->createContentLock(4, 'items', $secondUser, new DateTime('-10 hours'));

        $this->assertSame(4, $this->countContentLocks());

        // Run the clean job for second user's locks older than 3 hours.
        $args = [
            'process' => 'lockedit_db_content_lock_clean',
            'hours' => 3,
            'user_id' => [$secondUser->getId()],
        ];

        $this->runJob(DbContentLock::class, $args);

        // Should remove 2 locks (second user's 5h and 10h locks).
        $this->assertSame(2, $this->countContentLocks());
    }

    public function testCleanProcessWithInvalidHoursDoesNotRemove(): void
    {
        $this->createContentLock(1, 'items');

        $args = [
            'process' => 'lockedit_db_content_lock_clean',
            'hours' => 'invalid',
        ];

        $this->runJob(DbContentLock::class, $args);

        // Lock should not be removed due to invalid hours.
        $this->assertSame(1, $this->countContentLocks());
    }

    // =========================================================================
    // Tests: Multiple Users
    // =========================================================================

    public function testCleanProcessWithMultipleUserIds(): void
    {
        $secondUser = $this->createSecondUser();
        $thirdUser = $this->createSecondUser();
        $adminUser = $this->getCurrentUser();

        // Create locks.
        $this->createContentLock(1, 'items', $adminUser, new DateTime('-5 hours'));
        $this->createContentLock(2, 'items', $secondUser, new DateTime('-5 hours'));
        $this->createContentLock(3, 'items', $thirdUser, new DateTime('-5 hours'));

        $this->assertSame(3, $this->countContentLocks());

        // Run the clean job for second and third users.
        $args = [
            'process' => 'lockedit_db_content_lock_clean',
            'hours' => 1,
            'user_id' => [$secondUser->getId(), $thirdUser->getId()],
        ];

        $this->runJob(DbContentLock::class, $args);

        // Only admin's lock should remain.
        $this->assertSame(1, $this->countContentLocks());
    }
}
