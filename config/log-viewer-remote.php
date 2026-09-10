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
        'request' => 15,
        'download' => 300,
    ],

];
