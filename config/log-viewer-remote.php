<?php

declare(strict_types=1);

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
    | App chỉ cần ghi key mình đổi trong nhóm này (provider gộp sâu một cấp).
    */
    'agent' => [

        // Token chỉ đọc: chỉ gọi được `api/agent/*`, không tải / xoá file, không vào UI.
        // Sinh bằng `php artisan log-viewer-remote:secret --agent`. Shared secret cũng gọi
        // được các endpoint này.
        'token' => env('LOG_VIEWER_AGENT_TOKEN'),

        // Channel (config logging.channels) mà AGENT TOKEN được đọc, cách nhau bằng dấu phẩy.
        // Bỏ trống = chỉ channel của slow log. Shared secret không bị giới hạn (như UI Log
        // Viewer). Đừng mở channel chứa dữ liệu nhạy cảm (request AI, payload webhook…):
        // kết quả đi thẳng tới agent.
        'channels' => env('LOG_VIEWER_AGENT_CHANNELS'),

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

];
