<?php
// return [
//     'paths' => ['api/*', 'sanctum/csrf-cookie'],
//     'allowed_methods' => ['*'],
//     'allowed_origins' => ['http://localhost:3000'], // ★ Next.js のURLを許可
//     'allowed_origins_patterns' => [],
//     'allowed_headers' => ['*'],
//     'exposed_headers' => [],
//     'max_age' => 0,
//     'supports_credentials' => false,
// ];
return [
    // 'paths' => ['api/*'],
    // 'allowed_methods' => ['*'],
    // 'allowed_origins' => ['http://localhost:3000'], // ★ Next.js のURLを許可
    // 'allowed_headers' => ['*'],
  'paths' => ['api/*', 'sanctum/csrf-cookie'],
  'allowed_methods' => ['*'],
  'allowed_origins' => ['http://localhost:3000'],
  'allowed_origins_patterns' => [],
  'allowed_headers' => ['*'],
  'exposed_headers' => [],
  'max_age' => 0,
  'supports_credentials' => true,
];