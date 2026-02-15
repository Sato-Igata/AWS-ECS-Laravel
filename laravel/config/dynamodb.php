<?php

return [
    'region' => env('AWS_REGION', 'ap-northeast-1'),

    // 本番はECS Task Roleで認証するので credentials は書かない（重要）
    'endpoint' => env('DYNAMODB_ENDPOINT', null),

    'tables' => [
        'location'      => env('DYNAMODB_TABLE', 'location_info'),
        'location_user' => env('DYNAMODB_TABLE_USER', 'location_info_user'),
    ],
];