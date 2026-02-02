<?php declare(strict_types=1);

namespace LockEditTest\Controller\Admin;

use CommonTest\AbstractHttpControllerTestCase;
use DateTime;
use LockEditTest\LockEditTestTrait;

/**
 * Integration tests for content locking functionality.
 *
 * Note: Some controller tests are simplified because the full admin context
 * (user settings, etc.) isn't fully initialized in the test environment.
 * Core functionality is tested via API and entity operations.
 */
class ContentLockControllerTest extends AbstractHttpControllerTestCase
{
    use LockEditTestTrait;

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();
        $this->enableContentLock();
        $this->cleanContentLocks();
    }

    public function tearDown(): void
    {
        $this->cleanContentLocks();
        $this->cleanupResources();
        parent::tearDown();
    }

    // =========================================================================
    // Tests: Content Lock Creation
    // =========================================================================

    public function testContentLockCanBeCreated(): void
    {
        $item = $this->createItem();

        // Create lock directly.
        $contentLock = $this->createContentLock($item->id(), 'items');

        $this->assertNotNull($contentLock);
        $this->assertSame($item->id(), $contentLock->getEntityId());
        $this->assertSame('items', $contentLock->getEntityName());
        $this->assertNotNull($contentLock->getUser());
        $this->assertNotNull($contentLock->getCreated());
    }

    public function testContentLockCanBeRetrieved(): void
    {
        $item = $this->createItem();

        $this->createContentLock($item->id(), 'items');

        // Clear entity manager cache.
        $this->getEntityManager()->clear();

        // Retrieve lock.
        $contentLock = $this->getContentLock($item->id(), 'items');
        $this->assertNotNull($contentLock, 'Content lock should be retrievable');
    }

    // =========================================================================
    // Tests: Content Lock on Save via API
    // =========================================================================

    public function testSaveRemovesContentLockForSameUser(): void
    {
        $item = $this->createItem();

        // Create lock.
        $this->createContentLock($item->id(), 'items');
        $this->assertSame(1, $this->countContentLocks());

        // Save item via API.
        $this->api()->update('items', $item->id(), [
            'dcterms:title' => [
                [
                    'type' => 'literal',
                    'property_id' => 1,
                    '@value' => 'Updated Title',
                ],
            ],
        ]);

        // Lock should be removed.
        $this->assertSame(0, $this->countContentLocks(), 'Content lock should be removed after save');
    }

    public function testSaveByOtherUserThrowsExceptionWhenLocked(): void
    {
        $item = $this->createItem();
        $secondUser = $this->createSecondUser();

        // Create lock by second user.
        $this->createContentLock($item->id(), 'items', $secondUser);

        // Try to save as admin - should throw exception.
        $this->expectException(\Omeka\Api\Exception\ValidationException::class);
        $this->expectExceptionMessage('locked');

        $this->api()->update('items', $item->id(), [
            'dcterms:title' => [
                [
                    'type' => 'literal',
                    'property_id' => 1,
                    '@value' => 'Should Fail',
                ],
            ],
        ]);
    }

    public function testSaveWithBypassAllowsOverride(): void
    {
        $item = $this->createItem();
        $secondUser = $this->createSecondUser();

        // Create lock by second user.
        $this->createContentLock($item->id(), 'items', $secondUser);

        // Save with bypass.
        $response = $this->api()->update('items', $item->id(), [
            'dcterms:title' => [
                [
                    'type' => 'literal',
                    'property_id' => 1,
                    '@value' => 'Bypassed Update',
                ],
            ],
            'bypass_content_lock' => true,
        ]);

        $this->assertNotNull($response->getContent());
        $this->assertSame('Bypassed Update', $response->getContent()->displayTitle());

        // Lock should still exist for original user.
        $this->assertSame(1, $this->countContentLocks());
    }

    public function testNoLockCheckWhenFeatureDisabled(): void
    {
        $this->disableContentLock();

        $item = $this->createItem();
        $secondUser = $this->createSecondUser();

        // Create lock by second user.
        $this->createContentLock($item->id(), 'items', $secondUser);

        // Save as admin - should succeed because feature is disabled.
        $response = $this->api()->update('items', $item->id(), [
            'dcterms:title' => [
                [
                    'type' => 'literal',
                    'property_id' => 1,
                    '@value' => 'Updated When Disabled',
                ],
            ],
        ]);

        $this->assertNotNull($response->getContent());
        $this->assertSame('Updated When Disabled', $response->getContent()->displayTitle());
    }

    // =========================================================================
    // Tests: Content Lock on Delete via API
    // =========================================================================

    public function testDeleteRemovesContentLockForSameUser(): void
    {
        $item = $this->createItem();
        $itemId = $item->id();

        // Create lock.
        $this->createContentLock($itemId, 'items');
        $this->assertSame(1, $this->countContentLocks());

        // Delete item via API.
        $this->api()->delete('items', $itemId);

        // Remove from cleanup list since already deleted.
        $this->createdResources = array_filter(
            $this->createdResources,
            fn($r) => !($r['type'] === 'items' && $r['id'] === $itemId)
        );

        // Lock should be removed.
        $this->assertSame(0, $this->countContentLocks(), 'Content lock should be removed after delete');
    }

    public function testDeleteByOtherUserThrowsExceptionWhenLocked(): void
    {
        $item = $this->createItem();
        $secondUser = $this->createSecondUser();

        // Create lock by second user.
        $this->createContentLock($item->id(), 'items', $secondUser);

        // Try to delete as admin - should throw exception.
        $this->expectException(\Omeka\Api\Exception\ValidationException::class);
        $this->expectExceptionMessage('locked');

        $this->api()->delete('items', $item->id());
    }

    // =========================================================================
    // Tests: Expired Content Locks
    // =========================================================================

    public function testExpiredLocksAreRemovedOnSave(): void
    {
        $item = $this->createItem();
        $item2 = $this->createItem();

        // Create an expired lock directly in the database.
        $connection = $this->getConnection();
        $user = $this->getCurrentUser();

        $expiredDate = (new DateTime('-2 hours'))->format('Y-m-d H:i:s');
        $connection->executeStatement(
            'INSERT INTO content_lock (entity_id, entity_name, user_id, created) VALUES (?, ?, ?, ?)',
            [$item->id(), 'items', $user->getId(), $expiredDate]
        );

        // Set short duration for expiration.
        $this->settings()->set('lockedit_duration', 3600); // 1 hour

        $this->assertSame(1, $this->countContentLocks());

        // Create a new lock for item2 (which triggers removeExpiredContentLocks via event).
        $this->createContentLock($item2->id(), 'items');

        // Save item2 to trigger the lock check.
        $this->api()->update('items', $item2->id(), [
            'dcterms:title' => [
                [
                    'type' => 'literal',
                    'property_id' => 1,
                    '@value' => 'Trigger Cleanup',
                ],
            ],
        ]);

        // Clear entity manager cache.
        $this->getEntityManager()->clear();

        // Original expired lock should be removed.
        $oldLock = $this->getContentLock($item->id(), 'items');
        $this->assertNull($oldLock, 'Expired lock should be removed');
    }

    public function testExpiredLocksNotRemovedWhenDurationIsZero(): void
    {
        $item = $this->createItem();

        // Create an old lock directly in the database.
        $connection = $this->getConnection();
        $user = $this->getCurrentUser();

        $oldDate = (new DateTime('-24 hours'))->format('Y-m-d H:i:s');
        $connection->executeStatement(
            'INSERT INTO content_lock (entity_id, entity_name, user_id, created) VALUES (?, ?, ?, ?)',
            [$item->id(), 'items', $user->getId(), $oldDate]
        );

        $lockId = (int) $connection->lastInsertId();

        // Set duration to 0 (no expiration).
        $this->settings()->set('lockedit_duration', 0);

        // Create a second item and save it (to trigger cleanup).
        $secondItem = $this->createItem();
        $this->createContentLock($secondItem->id(), 'items');
        $this->api()->update('items', $secondItem->id(), [
            'dcterms:title' => [
                [
                    'type' => 'literal',
                    'property_id' => 1,
                    '@value' => 'Trigger',
                ],
            ],
        ]);

        // Original lock should still exist.
        $count = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM content_lock WHERE id = ?',
            [$lockId]
        );
        $this->assertSame(1, $count, 'Old lock should not be removed when duration is 0');
    }

    // =========================================================================
    // Tests: Multiple Resources
    // =========================================================================

    public function testMultipleResourcesCanBeLockedSimultaneously(): void
    {
        $item1 = $this->createItem();
        $item2 = $this->createItem();

        // Lock both.
        $this->createContentLock($item1->id(), 'items');
        $this->createContentLock($item2->id(), 'items');

        $this->assertSame(2, $this->countContentLocks());

        // Both locks should be independent.
        $lock1 = $this->getContentLock($item1->id(), 'items');
        $lock2 = $this->getContentLock($item2->id(), 'items');

        $this->assertNotNull($lock1);
        $this->assertNotNull($lock2);
        $this->assertNotSame($lock1->getId(), $lock2->getId());
    }

    public function testDifferentResourceTypesCanBeLockedWithSameId(): void
    {
        $item = $this->createItem();
        $itemSet = $this->createItemSet();

        // Lock with same entity ID but different types.
        $this->createContentLock($item->id(), 'items');
        $this->createContentLock($item->id(), 'item_sets');

        $this->assertSame(2, $this->countContentLocks());

        $lockItem = $this->getContentLock($item->id(), 'items');
        $lockItemSet = $this->getContentLock($item->id(), 'item_sets');

        $this->assertNotNull($lockItem);
        $this->assertNotNull($lockItemSet);
        $this->assertNotSame($lockItem->getId(), $lockItemSet->getId());
    }

    // =========================================================================
    // Tests: Lock on User Deletion
    // =========================================================================

    public function testContentLockRemovedWhenUserDeleted(): void
    {
        $item = $this->createItem();
        $secondUser = $this->createSecondUser();
        $userId = $secondUser->getId();

        // Create lock by second user.
        $this->createContentLock($item->id(), 'items', $secondUser);
        $this->assertSame(1, $this->countContentLocks());

        // Delete user.
        $entityManager = $this->getEntityManager();
        $user = $entityManager->find(\Omeka\Entity\User::class, $userId);
        $entityManager->remove($user);
        $entityManager->flush();

        // Remove from cleanup list.
        $this->createdUsers = array_filter($this->createdUsers, fn($id) => $id !== $userId);

        // Lock should be cascade deleted.
        $this->assertSame(0, $this->countContentLocks(), 'Lock should be removed when user is deleted');
    }

    // =========================================================================
    // Tests: Lock Refresh
    // =========================================================================

    public function testLockCanBeRefreshed(): void
    {
        $item = $this->createItem();

        // Create initial lock with old timestamp.
        $connection = $this->getConnection();
        $user = $this->getCurrentUser();

        $oldDate = (new DateTime('-1 hour'))->format('Y-m-d H:i:s');
        $connection->executeStatement(
            'INSERT INTO content_lock (entity_id, entity_name, user_id, created) VALUES (?, ?, ?, ?)',
            [$item->id(), 'items', $user->getId(), $oldDate]
        );

        $lockId = (int) $connection->lastInsertId();

        // Refresh lock by updating created timestamp.
        $newDate = (new DateTime('now'))->format('Y-m-d H:i:s');
        $connection->executeStatement(
            'UPDATE content_lock SET created = ? WHERE id = ?',
            [$newDate, $lockId]
        );

        // Verify refresh.
        $lock = $this->getEntityManager()->find(\LockEdit\Entity\ContentLock::class, $lockId);
        $this->getEntityManager()->refresh($lock);

        $this->assertGreaterThan(new DateTime('-1 minute'), $lock->getCreated());
    }

    // =========================================================================
    // Tests: ItemSet Locking
    // =========================================================================

    public function testItemSetSaveRemovesLockForSameUser(): void
    {
        $itemSet = $this->createItemSet();

        // Create lock.
        $this->createContentLock($itemSet->id(), 'item_sets');
        $this->assertSame(1, $this->countContentLocks());

        // Save item set via API.
        $this->api()->update('item_sets', $itemSet->id(), [
            'dcterms:title' => [
                [
                    'type' => 'literal',
                    'property_id' => 1,
                    '@value' => 'Updated Item Set Title',
                ],
            ],
        ]);

        // Lock should be removed.
        $this->assertSame(0, $this->countContentLocks(), 'Content lock should be removed after save');
    }

    public function testItemSetSaveByOtherUserThrowsExceptionWhenLocked(): void
    {
        $itemSet = $this->createItemSet();
        $secondUser = $this->createSecondUser();

        // Create lock by second user.
        $this->createContentLock($itemSet->id(), 'item_sets', $secondUser);

        // Try to save as admin - should throw exception.
        $this->expectException(\Omeka\Api\Exception\ValidationException::class);
        $this->expectExceptionMessage('locked');

        $this->api()->update('item_sets', $itemSet->id(), [
            'dcterms:title' => [
                [
                    'type' => 'literal',
                    'property_id' => 1,
                    '@value' => 'Should Fail',
                ],
            ],
        ]);
    }
}
