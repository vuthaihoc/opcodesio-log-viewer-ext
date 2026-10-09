<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Sinh shared secret và ghi LOG_VIEWER_SHARED_SECRET vào .env, kiểu key:generate.
 * Copy đúng giá trị này sang .env của mọi host còn lại.
 *
 * `--agent` sinh LOG_VIEWER_AGENT_TOKEN (token chỉ đọc cho agent) theo cùng cách.
 */
class GenerateSecretCommand extends Command
{
    protected $signature = 'log-viewer-remote:secret
        {--agent : Sinh LOG_VIEWER_AGENT_TOKEN (chỉ đọc api/agent/*) thay cho shared secret}
        {--show : Chỉ in ra, không ghi .env}
        {--force : Ghi đè nếu .env đã có giá trị}';

    protected $description = 'Sinh shared secret (hoặc agent token) cho Log Viewer và ghi vào .env';

    public function handle(): int
    {
        $key = $this->option('agent') ? 'LOG_VIEWER_AGENT_TOKEN' : 'LOG_VIEWER_SHARED_SECRET';
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
        $pattern = '/^'.$key.'=(.*)$/m';

        if (preg_match($pattern, $env, $matches) === 1) {
            if (trim($matches[1]) !== '' && ! $this->option('force')) {
                $this->warn($key.' đã có giá trị. Dùng --force để ghi đè (nhớ đổi trên MỌI host).');

                return self::FAILURE;
            }

            $env = (string) preg_replace($pattern, $key.'='.$secret, $env);
        } else {
            $env = rtrim($env, "\n")."\n\n".$key.'='.$secret."\n";
        }

        file_put_contents($path, $env);

        $this->info('Đã ghi '.$key.' vào .env. Copy cùng giá trị sang các host khác:');
        $this->line($secret);

        return self::SUCCESS;
    }
}
