<?php declare(strict_types=1);

namespace LockEdit;

use Common\Stdlib\PsrMessage;
use Omeka\Module\Exception\ModuleCannotInstallException;

/**
 * @var Module $this
 * @var \Laminas\ServiceManager\ServiceLocatorInterface $services
 * @var string $newVersion
 * @var string $oldVersion
 *
 * @var \Omeka\Api\Manager $api
 * @var \Omeka\View\Helper\Url $url
 * @var \Laminas\Log\Logger $logger
 * @var \Omeka\Settings\Settings $settings
 * @var \Laminas\I18n\View\Helper\Translate $translate
 * @var \Doctrine\DBAL\Connection $connection
 * @var \Laminas\Mvc\I18n\Translator $translator
 * @var \Doctrine\ORM\EntityManager $entityManager
 * @var \Omeka\Settings\SiteSettings $siteSettings
 * @var \Omeka\Mvc\Controller\Plugin\Messenger $messenger
 */
$plugins = $services->get('ControllerPluginManager');
$url = $services->get('ViewHelperManager')->get('url');
$api = $plugins->get('api');
$logger = $services->get('Omeka\Logger');
$settings = $services->get('Omeka\Settings');
$translate = $plugins->get('translate');
$translator = $services->get('MvcTranslator');
$connection = $services->get('Omeka\Connection');
$messenger = $plugins->get('messenger');
$siteSettings = $services->get('Omeka\Settings\Site');
$entityManager = $services->get('Omeka\EntityManager');

if (!method_exists($this, 'checkModuleActiveVersion') || !$this->checkModuleActiveVersion('Common', '3.4.76')) {
    $message = new \Omeka\Stdlib\Message(
        $translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
        'Common', '3.4.76'
    );
    throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
}

if (version_compare((string) $oldVersion, '3.4.2', '<')) {
    // Fix inverted logic for setting "lockedit_disable".
    // In previous versions, the logic was inverted: "lockedit_disable = false"
    // meant locking was disabled, and "true" meant enabled.
    // Now the logic is correct: "lockedit_disable = true" disables locking.
    // To preserve existing behavior, invert the current value.
    $currentValue = $settings->get('lockedit_disable');
    if ($currentValue !== null) {
        $newValue = !$currentValue;
        $settings->set('lockedit_disable', $newValue);
        $message = new PsrMessage(
            'The setting "Disable content locking" had inverted logic in previous versions and has been fixed. The value has been automatically inverted to preserve your existing behavior. Please verify the setting in module configuration.' // @translate
        );
        $messenger->addWarning($message);
        $logger->warn($message->getMessage());
    }
}
