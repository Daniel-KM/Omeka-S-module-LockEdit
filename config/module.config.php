<?php declare(strict_types=1);

namespace LockEdit;

return [
    'form_elements' => [
        'invokables' => [
            Form\SettingsFieldset::class => Form\SettingsFieldset::class,
        ],
    ],
    'translator' => [
        'translation_file_patterns' => [
            [
                'type' => 'gettext',
                'base_dir' => dirname(__DIR__) . '/language',
                'pattern' => '%s.mo',
                'text_domain' => null,
            ],
        ],
    ],
    'lockedit' => [
        'settings' => [
            'lockedit_disable' => false,
            // 86400 seconds = 24 hours. 14400 = 4 hours.
            'lockedit_duration' => 14400,
        ],
    ],
];
