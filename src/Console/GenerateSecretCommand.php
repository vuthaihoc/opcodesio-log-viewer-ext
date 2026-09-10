<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Sinh shared secret và ghi LOG_VIEWER_SHARED_SECRET vào .env, kiểu key:generate.
 * Copy đúng giá trị này sang .env của mọi host còn lại.
 */
class GenerateSecretCommand extends Command
{
    private const KEY = 'LOG_VIEWER_SHARED_SECRET';

    protected $signature = 'log-viewer-remote:secret
        {--show : Chỉ in ra, không ghi .env}
        {--force : Ghi đè nếu .env đã có giá trị}';

    protected $description = 'Sinh shared secret cho Log Viewer và ghi vào .env';

    public function handle(): int
    {
        $secret = Str::random(64);

        if ($this->option('show')) {
            $this->line($secret);

            return self::SUCCESS;
        }

        $path = $this->laravel->environmentFilePath();

        if (! is_file($path)) {
            $this->error("Không thấy file {$path}.");

            return self::FAILURE;
        }

        $env = (string) file_get_contents($path);
        $pattern = '/^'.self::KEY.'=(.*)$/m';

        if (preg_match($pattern, $env, $matches) === 1) {
            if (trim($matches[1]) !== '' && ! $this->option('force')) {
                $this->warn(self::KEY.' đã có giá trị. Dùng --force để ghi đè (nhớ đổi trên MỌI host).');

                return self::FAILURE;
            }

            $env = (string) preg_replace($pattern, self::KEY.'='.$secret, $env);
        } else {
            $env = rtrim($env, "\n")."\n\n".self::KEY.'='.$secret."\n";
        }

        file_put_contents($path, $env);

        $this->info('Đã ghi '.self::KEY.' vào .env. Copy cùng giá trị sang các host khác:');
        $this->line($secret);

        return self::SUCCESS;
    }
}
