<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Slow query log — xem docs/slow-log.md
|--------------------------------------------------------------------------
| Log ngay query chậm hơn ngưỡng, và log tổng kết khi một context (1 request /
| 1 job / 1 command) có quá nhiều query, tổng thời gian quá lớn, hoặc một query
| lặp nhiều lần (nghi N+1). Publish để đổi:
|   php artisan vendor:publish --tag=slow-log-config
*/

return [

    // Mặc định TẮT: cài package không tự sinh log ở app chưa muốn.
    'enabled' => env('SLOW_LOG_ENABLED', false),

    // Chỉ gắn listener ở các môi trường này (testing không có để test khỏi ồn).
    'environments' => ['local', 'production'],

    // Kênh log: null = kênh mặc định của app; tên channel app đã khai; hoặc tên trong
    // `dedicated` dưới đây để ghi ra file riêng (package tự khai channel đó).
    'channel' => env('SLOW_LOG_CHANNEL'),

    // Channel riêng do package khai (driver daily) nếu app chưa có channel cùng tên.
    // File riêng thì đọc nhanh, và cấp quyền cho agent theo channel được.
    // App chỉ cần ghi key mình đổi trong nhóm này (provider gộp sâu một cấp).
    'dedicated' => [
        'name' => 'slow-log',
        'path' => storage_path('logs/slow-log.log'),
        'days' => env('SLOW_LOG_DAYS', 14),
        'permission' => null,  // web và CLI khác user mà ghi chung file thì đặt 0666
    ],

    // Mức của dòng query chậm. Dòng tổng kết luôn là warning.
    'level' => env('SLOW_LOG_LEVEL', 'alert'),

    // Ngưỡng một query (ms). CLI/queue có ngưỡng riêng vì job nặng vốn chậm. 0 = tắt.
    'time_to_log' => env('SLOW_LOG_TIME_TO_LOG', 300),
    'time_to_log_cli' => env('SLOW_LOG_TIME_TO_LOG_CLI', 10000),

    // Ngưỡng tổng kết, độc lập nhau, 0 = tắt riêng từng cái.
    'total_to_log' => env('SLOW_LOG_TOTAL_TO_LOG', 30),          // > N query trong context
    'total_ms_to_log' => env('SLOW_LOG_TOTAL_MS_TO_LOG', 2000),  // tổng thời gian query > N ms
    'duplicate_to_log' => env('SLOW_LOG_DUPLICATE_TO_LOG', 10),  // một query lặp >= N lần

    // true = in thêm dòng `-> ` là SQL có giá trị thật (đã che). Chỉ có tác dụng khi APP_ENV=local.
    'raw_bindings' => env('SLOW_LOG_RAW_BINDINGS', false),

    'max_sql_length' => env('SLOW_LOG_MAX_SQL_LENGTH', 2000),  // cắt bớt SQL quá dài
    'max_groups' => env('SLOW_LOG_MAX_GROUPS', 200),           // trần số nhóm query giữ trong bộ nhớ
    'top_queries' => env('SLOW_LOG_TOP_QUERIES', 15),          // số dòng query in ra khi tổng kết

    /*
    | Truy vết nơi gọi query chậm (stack trace đã lọc nhiễu).
    */
    'trace' => [
        'enabled' => env('SLOW_LOG_TRACE', true),

        'max_frames' => 8,

        // Gói vendor bỏ khỏi trace — frame của chúng chỉ là nhiễu.
        'skip_vendors' => [
            'laravel/framework',
            'livewire/livewire',
            'barryvdh/laravel-debugbar',
        ],

        // storage/framework/views/<hash>.php → file .blade.php nguồn (marker PATH cuối file).
        'blade' => env('SLOW_LOG_TRACE_BLADE', true),

        // Cache Livewire 4 (storage/framework/views/livewire/…) → ⚡component nguồn.
        // App không có Livewire thì tầng này tự thành no-op, không cần tắt.
        'livewire' => env('SLOW_LOG_TRACE_LIVEWIRE', true),
    ],

    // Vá tên nguồn query của Debugbar cho Livewire 4 (Debugbar in hash thô).
    // Chỉ chạy ở local khi Debugbar có mặt; độc lập với `enabled`.
    'debugbar_livewire' => env('SLOW_LOG_DEBUGBAR_LIVEWIRE', true),

];
