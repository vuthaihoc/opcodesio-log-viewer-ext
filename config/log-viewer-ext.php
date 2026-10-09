<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| hocvt/log-viewer-remote — mở rộng opcodesio/log-viewer (repo opcodesio-log-viewer-ext)
|--------------------------------------------------------------------------
| Một file cho mọi phần của package: auth / host xa, agent phân tích log, slow log.
| Publish: php artisan vendor:publish --tag=log-viewer-ext-config
|
| Provider gộp ĐỆ QUY file của app với file này: app chỉ ghi đúng key mình đổi, kể cả key
| trong nhóm lồng nhau (`agent.page_key`, `slow_log.dedicated.permission`…); danh sách
| (`page_key`, `environments`, `skip_vendors`) thì app thay hẳn. Bản ≤ 1.2 dùng hai file
| `log-viewer-remote.php` + `slow-log.php`: app còn giữ thì vẫn được đọc, nhưng file này thắng.
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Shared secret
    |--------------------------------------------------------------------------
    | Bearer token cho request server-to-server giữa các host. Đặt CÙNG một giá
    | trị trên mọi host cần nói chuyện với nhau. Sinh bằng:
    |   php artisan log-viewer-remote:secret
    | LOG_VIEWER_PRODUCTION_TOKEN chỉ để tương thích với các host đang chạy.
    */
    'shared_secret' => env('LOG_VIEWER_SHARED_SECRET', env('LOG_VIEWER_PRODUCTION_TOKEN')),

    /*
    |--------------------------------------------------------------------------
    | Hosts khai bằng env
    |--------------------------------------------------------------------------
    | Dạng "id=https://host/log-viewer,id2=https://host2/log-viewer". Mỗi mục được
    | merge vào config('log-viewer.hosts') với auth.token = shared_secret. Host đã
    | khai tay trong config/log-viewer.php (tên đẹp, basic auth, header riêng) thì
    | giữ nguyên, env không ghi đè.
    */
    'hosts' => env('LOG_VIEWER_HOSTS', ''),

    /*
    |--------------------------------------------------------------------------
    | Timeout gọi sang host xa (giây)
    |--------------------------------------------------------------------------
    */
    'timeout' => [
        'request' => 15,    // xin link tải, lệnh check
        'download' => 300,  // stream file log về
        'forward' => 30,    // proxy API Log Viewer (?host=…) — bằng mặc định của vendor
        'agent' => 40,      // lệnh agent gọi sang host xa — phải lớn hơn agent.max_seconds
    ],

    /*
    |--------------------------------------------------------------------------
    | Agent — phân tích log ngay trên host có file (xem docs/agent.md)
    |--------------------------------------------------------------------------
    | Endpoint `{route_path}/api/agent/*` cho công cụ (agent AI, script) gọi bằng Bearer.
    | App chỉ cần ghi key mình đổi trong nhóm này (provider gộp đệ quy).
    */
    'agent' => [

        // Token chỉ đọc: chỉ gọi được `api/agent/*`, không tải / xoá file, không vào UI.
        // Sinh bằng `php artisan log-viewer-remote:secret --agent`. Shared secret cũng gọi
        // được các endpoint này.
        'token' => env('LOG_VIEWER_AGENT_TOKEN'),

        // Channel (config logging.channels) mà AGENT TOKEN được đọc, cách nhau bằng dấu phẩy.
        // Mặc định chỉ channel riêng của slow log. Shared secret không bị giới hạn (như UI Log
        // Viewer). Đừng mở channel chứa dữ liệu nhạy cảm (request AI, payload webhook…):
        // kết quả đi thẳng tới agent.
        'channels' => env('LOG_VIEWER_AGENT_CHANNELS', 'slow-log'),

        // Trần mỗi request: hết thì dừng ở đầu entry và trả `cursor` để gọi tiếp.
        'max_seconds' => (float) env('LOG_VIEWER_AGENT_MAX_SECONDS', 20),
        'max_bytes' => 0,  // 0 = không giới hạn byte, chỉ giới hạn thời gian

        // Số lượt quét chạy cùng lúc trên một host; hết chỗ thì trả 429 + retry_after.
        'slots' => 2,

        // Entry dài chỉ giữ phần đầu + phần cuối (context JSON nằm ở cuối).
        'head_bytes' => 65536,
        'tail_bytes' => 8192,

        // Nhiều worker ghi chung file nên thời gian lệch nhau: biên from/to nới thêm (giây).
        'slack' => 120,

        // Số khoá tối đa mỗi bảng gom (vượt 2× thì cắt về top).
        'cap' => 2000,

        // Khoá trang của slow log WEB, theo thứ tự ưu tiên: key context (phẳng hoặc
        // `a.b` lồng nhau). Host GraphQL nên đặt `graphql.name` trước `req.route`.
        'page_key' => ['req.route'],

        // regex => thay thế, áp lên path URL (log cũ chưa có req.route) sau khi đã quy về
        // route của app và thay số / token. Vd. ['#^/khoa-hoc/[^/]+$#' => '/khoa-hoc/{slug}'].
        'url_groups' => [],

        // Giữ kết quả của file đã đóng (ngày cũ, hoặc lâu không ghi thêm) — giây.
        'cache_ttl' => 86400,

        // Cursor (trạng thái quét dở) sống bao lâu — giây.
        'cursor_ttl' => 600,

        // Endpoint entries: số entry tối đa mỗi lần, số byte tối đa mỗi entry.
        'entries_limit' => 50,
        'entry_max_bytes' => 16384,
    ],

    /*
    |--------------------------------------------------------------------------
    | Slow query log — xem docs/slow-log.md
    |--------------------------------------------------------------------------
    | Log ngay query chậm hơn ngưỡng, và log tổng kết khi một context (1 request /
    | 1 job / 1 command) có quá nhiều query, tổng thời gian quá lớn, hoặc một query
    | lặp nhiều lần (nghi N+1).
    */
    'slow_log' => [

        // Mặc định TẮT: cài package không tự sinh log ở app chưa muốn.
        'enabled' => env('SLOW_LOG_ENABLED', false),

        // Chỉ gắn listener ở các môi trường này (testing không có để test khỏi ồn).
        'environments' => ['local', 'production'],

        // Kênh log: mặc định channel riêng `slow-log` (package tự khai, xem `dedicated`);
        // hoặc tên channel app đã khai; null / rỗng = kênh mặc định của app.
        'channel' => env('SLOW_LOG_CHANNEL', 'slow-log'),

        // Channel riêng do package khai (driver daily) nếu app chưa có channel cùng tên.
        // File riêng thì đọc nhanh, và cấp quyền cho agent theo channel được.
        // App chỉ cần ghi key mình đổi trong nhóm này (provider gộp đệ quy).
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
            // Mặc định tắt: dựa vào cách đặt tên cache nội bộ của Livewire; app Livewire 4 bật lên.
            'livewire' => env('SLOW_LOG_TRACE_LIVEWIRE', false),
        ],

        // Vá tên nguồn query của Debugbar cho Livewire 4 (Debugbar in hash thô).
        // Mặc định tắt; bật thì chỉ chạy ở local khi Debugbar có mặt, độc lập với `enabled`.
        'debugbar_livewire' => env('SLOW_LOG_DEBUGBAR_LIVEWIRE', false),

    ],

];
