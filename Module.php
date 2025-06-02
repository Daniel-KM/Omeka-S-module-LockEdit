<?php declare(strict_types=1);

/*
 * Copyright 2017-2025 Daniel Berthereau
 *
 * This software is governed by the CeCILL license under French law and abiding
 * by the rules of distribution of free software. You can use, modify and/or
 * redistribute the software under the terms of the CeCILL license as circulated
 * by CEA, CNRS and INRIA at the following URL "http://www.cecill.info".
 *
 * As a counterpart to the access to the source code and rights to copy, modify
 * and redistribute granted by the license, users are provided only with a
 * limited warranty and the software’s author, the holder of the economic
 * rights, and the successive licensors have only limited liability.
 *
 * In this respect, the user’s attention is drawn to the risks associated with
 * loading, using, modifying and/or developing or reproducing the software by
 * the user in light of its specific status of free software, that may mean that
 * it is complicated to manipulate, and that also therefore means that it is
 * reserved for developers and experienced professionals having in-depth
 * computer knowledge. Users are therefore encouraged to load and test the
 * software’s suitability as regards their requirements in conditions enabling
 * the security of their systems and/or data to be ensured and, more generally,
 * to use and operate it in the same conditions as regards security.
 *
 * The fact that you are presently reading this means that you have had
 * knowledge of the CeCILL license and that you accept its terms.
 */

namespace LockEdit;

if (!class_exists('Common\TraitModule', false)) {
    require_once dirname(__DIR__) . '/Common/TraitModule.php';
}

use Common\Stdlib\PsrMessage;
use Common\TraitModule;
use DateTime;
use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use LockEdit\Entity\ContentLock;
use Omeka\Module\AbstractModule;

/**
 * Lock Edit.
 *
 * @copyright Daniel Berthereau, 2022-2025
 * @license http://www.cecill.info/licences/Licence_CeCILL_V2.1-en.txt
 */
class Module extends AbstractModule
{
    use TraitModule;

    const NAMESPACE = __NAMESPACE__;

    protected $dependencies = [
        'Common',
    ];

    protected function preInstall(): void
    {
        $services = $this->getServiceLocator();
        $translate = $services->get('ControllerPluginManager')->get('translate');

        if (!method_exists($this, 'checkModuleActiveVersion') || !$this->checkModuleActiveVersion('Common', '3.4.67')) {
            $message = new \Omeka\Stdlib\Message(
                $translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
                'Common', '3.4.67'
            );
            throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
        }
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager): void
    {
        // Content locking in admin board.
        // It is useless in public board, because there is the moderation.
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Item',
            'view.edit.before',
            [$this, 'contentLockingOnEdit']
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\ItemSet',
            'view.edit.before',
            [$this, 'contentLockingOnEdit']
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Media',
            'view.edit.before',
            [$this, 'contentLockingOnEdit']
        );
        // The check for content locking can be done via `api.hydrate.pre` or
        // `api.update.pre`, that is bypassable in code.
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemAdapter::class,
            'api.update.pre',
            [$this, 'contentLockingOnSave']
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemSetAdapter::class,
            'api.update.pre',
            [$this, 'contentLockingOnSave']
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\MediaAdapter::class,
            'api.update.pre',
            [$this, 'contentLockingOnSave']
        );

        // There is no good event for deletion. So either js on layout, either
        // view.details and js, eiher override confirm form and/or delete confirm
        // to add elements or add a trigger in delete-confirm-details.
        // Here, view details + inline js to avoid to load a js in many views.
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Item',
            'view.details',
            [$this, 'contentLockingOnDeleteConfirm']
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\ItemSet',
            'view.details',
            [$this, 'contentLockingOnDeleteConfirm']
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Media',
            'view.details',
            [$this, 'contentLockingOnDeleteConfirm']
        );

        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemAdapter::class,
            'api.delete.pre',
            [$this, 'contentLockingOnDelete']
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemSetAdapter::class,
            'api.delete.pre',
            [$this, 'contentLockingOnDelete']
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\MediaAdapter::class,
            'api.delete.pre',
            [$this, 'contentLockingOnDelete']
        );

        // Add a job to clear content locks.
        $sharedEventManager->attach(
            \EasyAdmin\Form\CheckAndFixForm::class,
            'form.add_elements',
            [$this, 'handleEasyAdminJobsForm']
        );
        $sharedEventManager->attach(
            \EasyAdmin\Controller\Admin\CheckAndFixController::class,
            'easyadmin.job',
            [$this, 'handleEasyAdminJobs']
        );

        $sharedEventManager->attach(
            \Omeka\Form\SettingForm::class,
            'form.add_elements',
            [$this, 'handleMainSettings']
        );
    }

    public function contentLockingOnEdit(Event $event): void
    {
        /**
         * @var \Laminas\ServiceManager\ServiceLocatorInterface $services
         * @var \Omeka\Api\Representation\AbstractEntityRepresentation $resource
         * @var \Omeka\Entity\User $user
         * @var \Doctrine\ORM\EntityManager $entityManager
         * @var \Laminas\View\Renderer\PhpRenderer $view
         * @var \LockEdit\Entity\ContentLock $contentLock
         * @var \Omeka\Mvc\Controller\Plugin\Messenger $messenger
         */
        $view = $event->getTarget();
        $resource = $view->resource;
        if (!$resource) {
            return;
        }

        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        if ($settings->get('lockedit_disable')) {
            return;
        }

        // This mapping is needed because the api name is not available in the
        // representation.
        $resourceNames = [
            \Omeka\Api\Representation\ItemRepresentation::class => 'items',
            \Omeka\Api\Representation\ItemSetRepresentation::class => 'item_sets',
            \Omeka\Api\Representation\MediaRepresentation::class => 'media',
            'o:Item' => 'items',
            'o:ItemSet' => 'item_sets',
            'o:Media' => 'media',
        ];

        $entityId = $resource->id();
        $entityName = $resourceNames[get_class($resource)] ?? $resourceNames[$resource->getJsonLdType()] ?? null;
        if (!$entityId || !$entityName) {
            return;
        }

        $this->removeExpiredContentLocks();

        $user = $services->get('Omeka\AuthenticationService')->getIdentity();
        $entityManager = $services->get('Omeka\EntityManager');

        $contentLock = $entityManager->getRepository(ContentLock::class)
            ->findOneBy(['entityId' => $entityId, 'entityName' => $entityName]);

        if (!$contentLock) {
            $contentLock = new ContentLock($entityId, $entityName);
            $contentLock
                ->setUser($user)
                ->setCreated(new DateTIme('now'));
            $entityManager->persist($contentLock);
            try {
                // Flush is needed because the event does not run it.
                $entityManager->flush($contentLock);
                return;
            } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException $e) {
                // Sometime a duplicate entry occurs even if checked just above.
                try {
                    $entityManager->remove($contentLock);
                    $contentLock = $entityManager->getRepository(ContentLock::class)
                        ->findOneBy(['entityId' => $entityId, 'entityName' => $entityName]);
                } catch (\Exception $e) {
                    return;
                }
            } catch (\Exception $e) {
                // No content lock when there is an issue.
                $entityManager->remove($contentLock);
                return;
            }
        }

        $messenger = $services->get('ControllerPluginManager')->get('messenger');

        $contentLockUser = $contentLock->getUser();
        $isCurrentUser = $user->getId() === $contentLockUser->getId();

        if ($isCurrentUser) {
            $message = new PsrMessage(
                'You edit already this resource somewhere since {date}.', // @translate
                ['date' => $view->i18n()->dateFormat($contentLock->getCreated(), 'long', 'short')]
            );
            $messenger->addWarning($message);
            // Refresh the content lock: this is a new edition or a
            // submitted one. So the previous lock should be removed and a
            // a new one created, but it's simpler to override first one.
            $contentLock->setCreated(new DateTIme('now'));
            $entityManager->persist($contentLock);
            $entityManager->flush($contentLock);
            return;
        }

        $controllerNames = [
            'items' => 'item',
            'item_sets' => 'item-set',
            'media' => 'media',
        ];

        // TODO Add rights to bypass.

        /** @var \Laminas\Http\PhpEnvironment\Request $request */
        $request = $services->get('Application')->getMvcEvent()->getRequest();

        $isPost = $request->isPost();

        $message = new PsrMessage(
            'This content is being edited by the user {user_name} and is therefore locked to prevent other users changes. This lock is in place since {date}.', // @translate
            [
                'user_name' => $contentLockUser->getName(),
                'date' => $view->i18n()->dateFormat($contentLock->getCreated(), 'long', 'short'),
            ]
        );
        $messenger->add($isPost ? \Omeka\Mvc\Controller\Plugin\Messenger::ERROR : \Omeka\Mvc\Controller\Plugin\Messenger::WARNING, $message);

        $html = <<<'HTML'
            <label><input type="checkbox" name="bypass_content_lock" class="bypass-content-lock" value="1" form="edit-{entity_name}"/>{message_bypass}</label>
            <div class="easy-admin confirm-delete">
                <label><input type="checkbox" name="bypass_content_lock" class="bypass-content-lock" value="1" form="confirmform"/>{message_bypass}</label>
                <script>
                    $(document).ready(function() {
                        $('.easy-admin.confirm-delete').prependTo('#delete.sidebar #confirmform');
                        const buttonPageAction = $('#page-actions button[type=submit]');
                        const buttonSidebar = $('#delete.sidebar #confirmform input[name=submit]');
                        buttonPageAction.prop('disabled', true);
                        buttonSidebar.prop('disabled', true);
                        $('.bypass-content-lock').on('change', function () {
                            const button = $(this).parent().parent().hasClass('confirm-delete') ? buttonSidebar : buttonPageAction;
                            button.prop('disabled', !$(this).is(':checked'));
                        });
                    });
                </script>
            </div>
            HTML;
        $message = $view->translate('Bypass the lock'); // @translate
        $message = new PsrMessage($html, ['entity_name' => $controllerNames[$entityName], 'message_bypass' => $message]);
        $message->setEscapeHtml(false);
        $messenger->add($isPost ? \Omeka\Mvc\Controller\Plugin\Messenger::ERROR : \Omeka\Mvc\Controller\Plugin\Messenger::WARNING, $message);
    }

    public function contentLockingOnDeleteConfirm(Event $event): void
    {
        /**
         * @var \Laminas\ServiceManager\ServiceLocatorInterface $services
         * @var \Omeka\Api\Representation\AbstractEntityRepresentation $resource
         * @var \Omeka\Mvc\Status $status
         * @var \Omeka\Entity\User $user
         * @var \Doctrine\ORM\EntityManager $entityManager
         * @var \Laminas\View\Renderer\PhpRenderer $view
         * @var \LockEdit\Entity\ContentLock $contentLock
         * @var \Omeka\Mvc\Controller\Plugin\Messenger $messenger
         */

        // In the view show-details, the action is not known.
        $services = $this->getServiceLocator();
        $status = $services->get('Omeka\Status');
        $routeMatch = $status->getRouteMatch();
        if (!$status->isAdminRequest()
            || $routeMatch->getMatchedRouteName() !== 'admin/id'
            || $routeMatch->getParam('action') !== 'delete-confirm'
        ) {
            return;
        }

        $view = $event->getTarget();
        $resource = $view->resource;
        if (!$resource) {
            return;
        }

        $settings = $services->get('Omeka\Settings');
        if ($settings->get('lockedit_disable')) {
            return;
        }

        // This mapping is needed because the api name is not available in the
        // representation.
        $resourceNames = [
            \Omeka\Api\Representation\ItemRepresentation::class => 'items',
            \Omeka\Api\Representation\ItemSetRepresentation::class => 'item_sets',
            \Omeka\Api\Representation\MediaRepresentation::class => 'media',
            'o:Item' => 'items',
            'o:ItemSet' => 'item_sets',
            'o:Media' => 'media',
        ];

        $entityId = $resource->id();
        $entityName = $resourceNames[get_class($resource)] ?? $resourceNames[$resource->getJsonLdType()] ?? null;
        if (!$entityId || !$entityName) {
            return;
        }

        $this->removeExpiredContentLocks();

        $user = $services->get('Omeka\AuthenticationService')->getIdentity();
        $entityManager = $services->get('Omeka\EntityManager');

        $contentLock = $entityManager->getRepository(ContentLock::class)
            ->findOneBy(['entityId' => $entityId, 'entityName' => $entityName]);

        // Don't create or refresh a content lock on confirm delete.
        if (!$contentLock) {
            return;
        }

        // TODO Add rights to bypass.

        $contentLockUser = $contentLock->getUser();
        $isCurrentUser = $user->getId() === $contentLockUser->getId();

        $html = <<<'HTML'
            <div class="easy-admin confirm-delete">
                <p class="error">
                    <strong>%1$s</strong>
                    %2$s
                </p>
                %3$s
                <script>
                    $(document).ready(function() {
                        $('.easy-admin.confirm-delete').prependTo('#sidebar .sidebar-content #confirmform');
                        if (!$('.easy-admin.confirm-delete .bypass-content-lock').length) {
                            return;
                        }
                        const buttonSidebar = $('.sidebar #sidebar-confirm input[name=submit]');
                        buttonSidebar.prop('disabled', true);
                        $('.bypass-content-lock').on('change', function () {
                            buttonSidebar.prop('disabled', !$(this).is(':checked'));
                        });
                    });
                </script>
            </div>
            HTML;

        $translator = $services->get('MvcTranslator');

        $messageWarn = new PsrMessage('Warning:'); // @translate
        if ($isCurrentUser) {
            $message = new PsrMessage(
                'You edit this resource somewhere since {date}.', // @translate
                ['date' => $view->i18n()->dateFormat($contentLock->getCreated(), 'long', 'short')]
            );
            $messageInput = new PsrMessage('');
        } else {
            $message = new PsrMessage(
                'This content is being edited by the user {user_name} and is therefore locked to prevent other users changes. This lock is in place since {date}.', // @translate
                [
                    'user_name' => $contentLockUser->getName(),
                    'date' => $view->i18n()->dateFormat($contentLock->getCreated(), 'long', 'short'),
                ]
            );
            $messageInput = new PsrMessage('Bypass the lock'); // @translate
            $messageInput = new PsrMessage('<label><input type="checkbox" name="bypass_content_lock" class="bypass-content-lock" value="1" form="confirmform"/>{message_bypass}</label>', ['message_bypass' => $messageInput->setTranslator($translator)]);
        }

        echo sprintf($html, $messageWarn->setTranslator($translator), $message->setTranslator($translator), $messageInput->setTranslator($translator));
    }

    public function contentLockingOnSave(Event $event): void
    {
        /**
         * @var \Laminas\ServiceManager\ServiceLocatorInterface $services
         * @var \Omeka\Entity\User $user
         * @var \Doctrine\ORM\EntityManager $entityManager
         * @var \LockEdit\Entity\ContentLock $contentLock
         * @var \Omeka\Api\Request $request
         */
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        if (!$settings->get('lockedit_disable')) {
            return;
        }

        $request = $event->getParam('request');

        $entityId = $request->getId();
        $entityName = $request->getResource();
        if (!$entityId || !$entityName) {
            return;
        }

        $this->removeExpiredContentLocks();

        $entityManager = $services->get('Omeka\EntityManager');

        $contentLock = $entityManager->getRepository(ContentLock::class)
            ->findOneBy(['entityId' => $entityId, 'entityName' => $entityName]);
        if (!$contentLock) {
            return;
        }

        $user = $services->get('Omeka\AuthenticationService')->getIdentity();
        $contentLockUser = $contentLock->getUser();
        if ($user->getId() === $contentLockUser->getId()) {
            // The content lock won't be removed in case of a validation
            // exception.
            $entityManager->remove($contentLock);
            return;
        }

        $i18n = $services->get('ViewHelperManager')->get('i18n');

        // When a lock is bypassed, keep it for the original user.
        if ($request->getValue('bypass_content_lock')) {
            $messenger = $services->get('ControllerPluginManager')->get('messenger');
            $message = new PsrMessage(
                'The lock in place since {date} has been bypassed, but the user {user_name} can override it on save.', // @translate
                [
                    'date' => $i18n->dateFormat($contentLock->getCreated(), 'long', 'short'),
                    'user_name' => $contentLockUser->getName(),
                ]
            );
            $messenger->addWarning($message);
            return;
        }

        // Keep the message for backend api process.
        $message = new PsrMessage(
            'User {user} (#{userid}) tried to save {resource_name} #{resource_id} edited by the user {user_name} (#{user_id}) since {date}.', // @translate
            [
                'user' => $user->getName(),
                'userid' => $user->getId(),
                'resource_name' => $entityName,
                'resource_id' => $entityId,
                'user_name' => $contentLockUser->getName(),
                'user_id' => $contentLockUser->getId(),
                'date' => $i18n->dateFormat($contentLock->getCreated(), 'long', 'short'),
            ]
        );
        $services->get('Omeka\Logger')->err($message);

        $message = new PsrMessage(
            'This content is being edited by the user {user_name} and is therefore locked to prevent other users changes. This lock is in place since {date}.', // @translate
            [
                'user_name' => $contentLockUser->getName(),
                'date' => $i18n->dateFormat($contentLock->getCreated(), 'long', 'short'),
            ]
        );

        // Throw exception for frontend and backend.
        throw new \Omeka\Api\Exception\ValidationException((string) $message);
    }

    /**
     * Remove content lock on resource deletion.
     *
     * The primary key is not a join column, so old deleted record can remain.
     */
    public function contentLockingOnDelete(Event $event): void
    {
        /**
         * @var \Doctrine\ORM\EntityManager $entityManager
         * @var \LockEdit\Entity\ContentLock $contentLock
         * @var \Omeka\Api\Request $request
         */
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        // Remove the content lock on delete even when the feature is disabled.
        $isContentLockEnabled = !$settings->get('lockedit_disable');

        $request = $event->getParam('request');

        $entityId = $request->getId();
        $entityName = $request->getResource();
        if (!$entityId || !$entityName) {
            return;
        }

        $entityManager = $services->get('Omeka\EntityManager');

        $contentLock = $entityManager->getRepository(ContentLock::class)
            ->findOneBy(['entityId' => $entityId, 'entityName' => $entityName]);
        if (!$contentLock) {
            return;
        }

        if (!$isContentLockEnabled) {
            // The content lock won't be removed in case of a validation
            // exception.
            $entityManager->remove($contentLock);
            return;
        }

        $i18n = $services->get('ViewHelperManager')->get('i18n');

        $user = $services->get('Omeka\AuthenticationService')->getIdentity();
        $contentLockUser = $contentLock->getUser();

        if ($user->getId() === $contentLockUser->getId()) {
            $messenger = $services->get('ControllerPluginManager')->get('messenger');
            $message = new PsrMessage(
                'You removed the resource you are editing somewhere since {date}.', // @translate
                ['date' => $i18n->dateFormat($contentLock->getCreated(), 'long', 'short')]
            );
            $messenger->addWarning($message);
            // The content lock won't be removed in case of a validation
            // exception.
            $entityManager->remove($contentLock);
            return;
        }

        // When lock is bypassed on delete, don't keep it for the user editing.

        // TODO Find a better way to check bypass content lock.
        if ($request->getValue('bypass_content_lock')
            || $request->getOption('bypass_content_lock')
            || !empty($_POST['bypass_content_lock'])
        ) {
            $messenger = $services->get('ControllerPluginManager')->get('messenger');
            $message = new PsrMessage(
                'You removed a resource currently locked in edition by {user_name} since {date}.', // @translate
                [
                    'user_name' => $contentLockUser->getName(),
                    'date' => $i18n->dateFormat($contentLock->getCreated(), 'long', 'short'),
                ]
            );
            $messenger->addWarning($message);
            // Will be flushed automatically in post.
            $entityManager->remove($contentLock);
            return;
        }

        // Keep the message for backend api process.
        $message = new PsrMessage(
            'User {user} (#{userid}) tried to delete {resource_name} #{resource_id} edited by the user {user_name} (#{user_id}) since {date}.', // @translate
            [
                'user' => $user->getName(),
                'userid' => $user->getId(),
                'resource_name' => $entityName,
                'resource_id' => $entityId,
                'user_name' => $contentLockUser->getName(),
                'user_id' => $contentLockUser->getId(),
                'date' => $i18n->dateFormat($contentLock->getCreated(), 'long', 'short'),
            ]
        );
        $services->get('Omeka\Logger')->err($message);

        $message = new PsrMessage(
            'This content is being edited by the user {user_name} and is therefore locked to prevent other users changes. This lock is in place since {date}.', // @translate
            [
                'user_name' => $contentLockUser->getName(),
                'date' => $i18n->dateFormat($contentLock->getCreated(), 'long', 'short'),
            ]
        );

        // Throw exception for frontend and backend.
        // TODO Redirect to browse or show view instead of displaying the error.
        throw new \Omeka\Api\Exception\ValidationException((string) $message);
    }

    public function handleEasyAdminJobsForm(Event $event): void
    {
        /**
         * @var \EasyAdmin\Form\CheckAndFixForm $form
         * @var \Laminas\Form\Element\Radio $process
         */
        $form = $event->getTarget();
        $fieldset = $form->get('module_tasks');

        $process = $fieldset->get('process');
        $valueOptions = $process->getValueOptions();
        $valueOptions['lockedit_db_content_lock_check'] = 'Lock Edit: Check existing content locks'; // @translate
        $valueOptions['lockedit_db_content_lock_clean'] = 'Lock Edit: Remove existing content locks'; // @translate
        $process->setValueOptions($valueOptions);

        $fieldset
            ->add([
                'type' => \Laminas\Form\Fieldset::class,
                'name' => 'lockedit_db_content_lock',
                'options' => [
                    'label' => 'Options to remove content locks', // @translate
                ],
                'attributes' => [
                    'class' => 'lockedit_db_content_lock_check lockedit_db_content_lock_clean',
                ],
            ])
            ->get('lockedit_db_content_lock')
            ->add([
                'name' => 'hours',
                'type' => \Common\Form\Element\OptionalNumber::class,
                'options' => [
                    'label' => 'Older than this number of hours', // @translate
                ],
                'attributes' => [
                    'id' => 'lockedit_db_content_lock-hours',
                ],
            ])
            ->add([
                'name' => 'user_id',
                'type' => \Common\Form\Element\OptionalUserSelect::class,
                'options' => [
                    'label' => 'Belonging to these users', // @translate
                    'empty_option' => '',
                ],
                'attributes' => [
                    'id' => 'lockedit_db_content_lock-user_id',
                    'multiple' => true,
                    'required' => false,
                    'class' => 'chosen-select',
                    'data-placeholder' => 'Select users…', // @translate
                ],
            ]);
    }

    public function handleEasyAdminJobs(Event $event): void
    {
        $process = $event->getParam('process');
        if ($process === 'lockedit_db_content_lock_check' || $process === 'lockedit_db_content_lock_clean') {
            $params = $event->getParam('params');
            $event->setParam('job', \LockEdit\Job\DbContentLock::class);
            $event->setParam('args', ['process' => $process] + ($params['module_tasks']['lockedit_db_content_lock'] ?? []));
        }
    }

    protected function removeExpiredContentLocks(): void
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $duration = (int) $settings->get('lockedit_duration');
        if (!$duration) {
            return;
        }

        // Use connection, because entity manager won't remove values and will
        // cause complex flush process/sync.

        /** @var \Doctrine\DBAL\Connection $connection */
        $connection = $services->get('Omeka\Connection');
        $connection->executeStatement(
            'DELETE FROM content_lock WHERE created < DATE_SUB(NOW(), INTERVAL :duration SECOND)',
            ['duration' => $duration]
        );
    }
}
