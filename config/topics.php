<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Known Topics for Category/Tag Auto-Creation
    |--------------------------------------------------------------------------
    |
    | Ключевые слова -> каноническое имя темы. Используется общим для
    | CategoryDetectorService и TagDetectorService: когда ни одна
    | существующая категория/тег не совпали по названию, ищем по этому
    | словарю и заводим новую запись автоматически.
    |
    */
    'known' => [
        'laravel' => 'Laravel',
        'symfony' => 'Symfony',
        'wordpress' => 'WordPress',
        'cakephp' => 'CakePHP',
        'typo3' => 'TYPO3',
        'docker' => 'Docker',
        'kubernetes' => 'Kubernetes',
        'mysql' => 'MySQL',
        'postgresql' => 'PostgreSQL',
        'redis' => 'Redis',
        'javascript' => 'JavaScript',
        'node.js' => 'Node.js',
        'nodejs' => 'Node.js',
        'python' => 'Python',
        'devops' => 'DevOps',
        'security' => 'Security',
        'ai' => 'AI',
    ],
];
