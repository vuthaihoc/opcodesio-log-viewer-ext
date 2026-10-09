<?php

declare(strict_types=1);

// Có vendor riêng (chạy trong repo package) thì dùng; không thì tự nạp PSR-4 cho src + tests
// (chạy bằng phpunit của app đang dùng package).
if (is_file(__DIR__.'/../vendor/autoload.php')) {
    require __DIR__.'/../vendor/autoload.php';
}

spl_autoload_register(static function (string $class): void {
    foreach (['HocVT\\LogViewerRemote\\Tests\\' => __DIR__.'/', 'HocVT\\LogViewerRemote\\' => __DIR__.'/../src/'] as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';

            if (is_file($file)) {
                require $file;
            }

            return;
        }
    }
});
