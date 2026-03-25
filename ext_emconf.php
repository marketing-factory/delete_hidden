<?php
$EM_CONF['delete_hidden'] = [
    'title' => 'Delete Hidden',
    'description' => 'Soft-deletes hidden tt_content records (including b13/container children) older than a configurable number of days.',
    'category' => 'be',
    'version' => '1.0.0',
    'state' => 'stable',
    'author' => 'Marketing Factory TYPO3 Team <typo3@marketing-factory.de>',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-13.99.99',
        ],
    ],
];
