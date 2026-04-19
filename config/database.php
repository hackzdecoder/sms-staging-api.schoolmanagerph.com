<?php

return [
  'default' => env('DB_CONNECTION', 'users_main'),

  'connections' => [
    'users_main' => [
      'driver' => 'mysql',
      'host' => env('DB_HOST', '127.0.0.1'),
      'port' => env('DB_PORT', '3306'),
      'database' => env('APP_ENV') === 'dev'
        ? env('DB_USERS_MAIN_DATABASE')
        : env('DB_PRODUCTION_USERS_DATABASE'),
      'username' => env('APP_ENV') === 'dev'
        ? env('DB_USERS_MAIN_USERNAME', 'root')
        : env('DB_PRODUCTION_USERS_USERNAME'),
      'password' => env('APP_ENV') === 'dev'
        ? env('DB_USERS_MAIN_PASSWORD', '')
        : env('DB_PRODUCTION_USERS_PASSWORD'),
      'charset' => 'utf8mb4',
      'collation' => 'utf8mb4_unicode_ci',
      'prefix' => '',
      'strict' => true,
      'engine' => null,
      'options' => [
        \PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci',
      ],
    ],

    'trademarks' => [
      'driver' => 'mysql',
      'host' => env('DB_HOST', '127.0.0.1'),
      'port' => env('DB_PORT', '3306'),
      'database' => env('APP_ENV') === 'dev'
        ? env('DB_TRADEMARKS_DATABASE_DEV')
        : env('DB_TRADEMARKS_DATABASE_PROD'),
      'username' => env('APP_ENV') === 'dev'
        ? env('DB_TRADEMARKS_USERNAME_DEV')
        : env('DB_TRADEMARKS_USERNAME_PROD'),
      'password' => env('APP_ENV') === 'dev'
        ? env('DB_TRADEMARKS_PASSWORD_DEV', '')
        : env('DB_TRADEMARKS_PASSWORD_PROD'),
      'charset' => 'utf8mb4',
      'collation' => 'utf8mb4_unicode_ci',
      'prefix' => '',
      'strict' => true,
      'engine' => null,
      'options' => [
        \PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci',
      ],
    ],
  ],

  'migrations' => [
    'table' => 'migrations',
  ],

  'redis' => [
    'client' => env('REDIS_CLIENT', 'phpredis'),

    'default' => [
      'host' => env('REDIS_HOST', '127.0.0.1'),
      'password' => env('REDIS_PASSWORD'),
      'port' => env('REDIS_PORT', '6379'),
      'database' => env('REDIS_DB', '0'),
    ],

    'cache' => [
      'host' => env('REDIS_HOST', '127.0.0.1'),
      'password' => env('REDIS_PASSWORD'),
      'port' => env('REDIS_PORT', '6379'),
      'database' => env('REDIS_CACHE_DB', '1'),
    ],
  ],
];