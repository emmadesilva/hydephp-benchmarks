<?php

return [
    'production' => false,
    'baseUrl' => '',
    'title' => 'Bench',
    'collections' => [
        'posts' => [
            'path' => 'posts/{filename}',
            'sort' => '-date',
        ],
    ],
];
