<?php
$EM_CONF['delete_hidden'] = [
    'title' => 'Delete Hidden',
    'description' => 'Soft-deletes hidden tt_content records (including b13/container children) older than a configurable number of days.',
    'category' => 'be',
    'author' => 'Ingo Schmitt',
    'author_email' => 'ingo.schmitt@marketing-factory.de',
    'author_company' => 'Marketing Factory Digital GmbH',
    'version' => '1.0.2',
    'state' => 'stable',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-13.99.99',
        ],
        'suggests' => [
            'container' => '*',
        ],
    ],
];
