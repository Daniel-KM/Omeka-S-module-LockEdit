<?php declare(strict_types=1);

namespace LockEditTest\Entity;

use DateTime;
use LockEdit\Entity\ContentLock;
use Omeka\Entity\User;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ContentLock entity.
 */
class ContentLockTest extends TestCase
{
    public function testConstructorSetsEntityIdAndName(): void
    {
        $contentLock = new ContentLock(123, 'items');

        $this->assertSame(123, $contentLock->getEntityId());
        $this->assertSame('items', $contentLock->getEntityName());
    }

    public function testSetAndGetUser(): void
    {
        $contentLock = new ContentLock(1, 'items');

        $user = $this->createMock(User::class);
        $contentLock->setUser($user);

        $this->assertSame($user, $contentLock->getUser());
    }

    public function testSetAndGetCreated(): void
    {
        $contentLock = new ContentLock(1, 'items');

        $created = new DateTime('2025-01-15 10:30:00');
        $contentLock->setCreated($created);

        $this->assertSame($created, $contentLock->getCreated());
    }

    public function testIdIsNullBeforePersistence(): void
    {
        $contentLock = new ContentLock(1, 'items');

        $this->assertNull($contentLock->getId());
    }

    public function testFluentInterface(): void
    {
        $contentLock = new ContentLock(1, 'items');
        $user = $this->createMock(User::class);
        $created = new DateTime();

        $result = $contentLock
            ->setUser($user)
            ->setCreated($created);

        $this->assertSame($contentLock, $result);
    }

    /**
     * @dataProvider entityNameProvider
     */
    public function testAcceptsValidEntityNames(string $entityName): void
    {
        $contentLock = new ContentLock(1, $entityName);

        $this->assertSame($entityName, $contentLock->getEntityName());
    }

    public function entityNameProvider(): array
    {
        return [
            'items' => ['items'],
            'item_sets' => ['item_sets'],
            'media' => ['media'],
        ];
    }
}
