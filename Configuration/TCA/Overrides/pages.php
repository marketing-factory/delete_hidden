<?php

declare(strict_types=1);

\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addTCAcolumns(
    'pages',
    [
        'tx_delete_hidden_exclude' => [
            'label' => 'LLL:EXT:delete_hidden/Resources/Private/Language/locallang_db.xlf:pages.tx_delete_hidden_exclude',
            'config' => [
                'type' => 'check',
                'renderType' => 'checkboxToggle',
                'default' => 0,
                'items' => [
                    [
                        'label' => '',
                    ],
                ],
            ],
        ],
    ]
);

\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addToAllTCAtypes(
    'pages',
    'tx_delete_hidden_exclude',
    '',
    'after:--palette--;;miscellaneous'
);
