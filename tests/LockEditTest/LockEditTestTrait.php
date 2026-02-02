<?php declare(strict_types=1);

namespace LockEditTest;

use DateTime;
use Laminas\ServiceManager\ServiceLocatorInterface;
use LockEdit\Entity\ContentLock;
use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Entity\Job;
use Omeka\Entity\User;

/**
 * Shared test helpers for LockEdit module tests.
 */
trait LockEditTestTrait
{
    /**
     * @var ServiceLocatorInterface
     */
    protected $services;

    /**
     * @var array IDs of items created during tests (for cleanup).
     */
    protected array $createdResources = [];

    /**
     * @var array Users created during tests (for cleanup).
     */
    protected array $createdUsers = [];

    /**
     * @var bool Whether admin is logged in.
     */
    protected bool $isLoggedIn = false;

    /**
     * Get the service locator.
     */
    protected function getServiceLocator(): ServiceLocatorInterface
    {
        if (isset($this->application) && $this->application !== null) {
            return $this->application->getServiceManager();
        }
        return $this->getApplication()->getServiceManager();
    }

    /**
     * Reset the cached service locator.
     */
    protected function resetServiceLocator(): void
    {
        $this->services = null;
    }

    /**
     * Get the API manager.
     */
    protected function api(): ApiManager
    {
        if ($this->isLoggedIn) {
            $this->ensureLoggedIn();
        }
        return $this->getServiceLocator()->get('Omeka\ApiManager');
    }

    /**
     * Get the entity manager.
     */
    public function getEntityManager(): \Doctrine\ORM\EntityManager
    {
        return $this->getServiceLocator()->get('Omeka\EntityManager');
    }

    /**
     * Get the database connection.
     */
    protected function getConnection(): \Doctrine\DBAL\Connection
    {
        return $this->getServiceLocator()->get('Omeka\Connection');
    }

    /**
     * Get settings service.
     */
    protected function settings(): \Omeka\Settings\Settings
    {
        return $this->getServiceLocator()->get('Omeka\Settings');
    }

    /**
     * Login as admin user.
     */
    protected function loginAdmin(): void
    {
        $this->isLoggedIn = true;
        $this->ensureLoggedIn();
    }

    /**
     * Ensure admin is logged in on the current application instance.
     */
    protected function ensureLoggedIn(): void
    {
        $services = $this->getServiceLocator();
        $auth = $services->get('Omeka\AuthenticationService');

        if ($auth->hasIdentity()) {
            return;
        }

        $adapter = $auth->getAdapter();
        $adapter->setIdentity('admin@example.com');
        $adapter->setCredential('root');
        $auth->authenticate();
    }

    /**
     * Get the current authenticated user.
     */
    protected function getCurrentUser(): ?User
    {
        $auth = $this->getServiceLocator()->get('Omeka\AuthenticationService');
        return $auth->getIdentity();
    }

    /**
     * Logout current user.
     */
    protected function logout(): void
    {
        $this->isLoggedIn = false;
        $auth = $this->getServiceLocator()->get('Omeka\AuthenticationService');
        $auth->clearIdentity();
    }

    /**
     * Enable content locking feature.
     */
    protected function enableContentLock(): void
    {
        $this->settings()->set('lockedit_disable', false);
        $this->settings()->set('lockedit_duration', 3600);
    }

    /**
     * Disable content locking feature.
     */
    protected function disableContentLock(): void
    {
        $this->settings()->set('lockedit_disable', true);
    }

    /**
     * Remove all content locks from the database.
     */
    protected function cleanContentLocks(): void
    {
        $this->getConnection()->executeStatement('DELETE FROM content_lock');
    }

    /**
     * Count content locks in the database.
     */
    protected function countContentLocks(): int
    {
        return (int) $this->getConnection()->fetchOne('SELECT COUNT(*) FROM content_lock');
    }

    /**
     * Get a content lock by entity ID and name.
     */
    protected function getContentLock(int $entityId, string $entityName): ?ContentLock
    {
        return $this->getEntityManager()
            ->getRepository(ContentLock::class)
            ->findOneBy(['entityId' => $entityId, 'entityName' => $entityName]);
    }

    /**
     * Create a content lock.
     */
    protected function createContentLock(int $entityId, string $entityName, ?User $user = null, ?DateTime $created = null): ContentLock
    {
        $entityManager = $this->getEntityManager();

        if (!$user) {
            $user = $this->getCurrentUser();
        }

        $contentLock = new ContentLock($entityId, $entityName);
        $contentLock->setUser($user);
        $contentLock->setCreated($created ?? new DateTime('now'));

        $entityManager->persist($contentLock);
        $entityManager->flush();

        return $contentLock;
    }

    /**
     * Create a test item.
     *
     * @param array $data Item data with property terms as keys.
     */
    protected function createItem(array $data = []): ItemRepresentation
    {
        $itemData = $data ?: [
            'dcterms:title' => [
                [
                    'type' => 'literal',
                    'property_id' => 1,
                    '@value' => 'Test Item for Content Lock',
                ],
            ],
        ];

        $response = $this->api()->create('items', $itemData);
        $item = $response->getContent();
        $this->createdResources[] = ['type' => 'items', 'id' => $item->id()];

        return $item;
    }

    /**
     * Create a test item set.
     */
    protected function createItemSet(array $data = []): \Omeka\Api\Representation\ItemSetRepresentation
    {
        $itemSetData = $data ?: [
            'dcterms:title' => [
                [
                    'type' => 'literal',
                    'property_id' => 1,
                    '@value' => 'Test Item Set for Content Lock',
                ],
            ],
        ];

        $response = $this->api()->create('item_sets', $itemSetData);
        $itemSet = $response->getContent();
        $this->createdResources[] = ['type' => 'item_sets', 'id' => $itemSet->id()];

        return $itemSet;
    }

    /**
     * Create a second test user (editor role).
     */
    protected function createSecondUser(): User
    {
        $entityManager = $this->getEntityManager();

        $user = new User();
        $user->setEmail('editor_' . uniqid() . '@example.com');
        $user->setName('Editor User');
        $user->setRole('editor');
        $user->setIsActive(true);

        $entityManager->persist($user);
        $entityManager->flush();

        $this->createdUsers[] = $user->getId();

        return $user;
    }

    /**
     * Run a job synchronously for testing.
     *
     * @param string $jobClass Job class name.
     * @param array $args Job arguments.
     * @param bool $expectError If true, don't rethrow exceptions.
     */
    protected function runJob(string $jobClass, array $args, bool $expectError = false): Job
    {
        $services = $this->getServiceLocator();
        $entityManager = $services->get('Omeka\EntityManager');
        $auth = $services->get('Omeka\AuthenticationService');

        // Create job entity.
        $job = new Job();
        $job->setStatus(Job::STATUS_STARTING);
        $job->setClass($jobClass);
        $job->setArgs($args);
        $job->setOwner($auth->getIdentity());

        $entityManager->persist($job);
        $entityManager->flush();

        // Run job synchronously.
        $jobInstance = new $jobClass($job, $services);
        $job->setStatus(Job::STATUS_IN_PROGRESS);
        $job->setStarted(new DateTime('now'));
        $entityManager->flush();

        try {
            $jobInstance->perform();
            if ($job->getStatus() === Job::STATUS_IN_PROGRESS) {
                $job->setStatus(Job::STATUS_COMPLETED);
            }
        } catch (\Throwable $e) {
            $job->setStatus(Job::STATUS_ERROR);
            if (!$expectError) {
                throw $e;
            }
        }

        $job->setEnded(new DateTime('now'));
        $entityManager->flush();

        return $job;
    }

    /**
     * Clean up created resources after test.
     */
    protected function cleanupResources(): void
    {
        // Delete created items/item sets.
        foreach ($this->createdResources as $resource) {
            try {
                $this->api()->delete($resource['type'], $resource['id']);
            } catch (\Exception $e) {
                // Ignore errors during cleanup.
            }
        }
        $this->createdResources = [];

        // Delete created users.
        $entityManager = $this->getEntityManager();
        foreach ($this->createdUsers as $userId) {
            try {
                $user = $entityManager->find(User::class, $userId);
                if ($user) {
                    $entityManager->remove($user);
                }
            } catch (\Exception $e) {
                // Ignore errors during cleanup.
            }
        }
        if ($this->createdUsers) {
            $entityManager->flush();
        }
        $this->createdUsers = [];
    }
}
