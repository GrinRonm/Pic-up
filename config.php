<?php
/**
 * ImgHost Configuration
 */

return [
    // Database
    'db_path' => __DIR__ . '/database.sqlite',
    
    // Telegram Bot
    'telegram' => [
        'bot_token' => '7396400534:AAE09x3-7JQpZKeMxPkIAVR1g9F_Hs3_S6c',
        'chat_id' => '-1003724858822',
    ],
    
    // Upload settings
    'upload' => [
        'max_files' => 5,
        'max_size' => 15 * 1024 * 1024, // 15MB
        'allowed_types' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
        'tmp_dir' => __DIR__ . '/uploads/tmp/',
        'cache_dir' => __DIR__ . '/uploads/cache/',
    ],
    
    // Application
    'app' => [
        'url' => 'https://pic-up.ae0.ru',
        'expiry_days' => 30,
        'debug' => false,
    ],
    
    // Logging
    'log' => [
        'enabled' => true,
        'file' => __DIR__ . '/logs.txt',
    ]
];

