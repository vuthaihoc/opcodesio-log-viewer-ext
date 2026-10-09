<?php

declare(strict_types=1);

namespace HocVT\LogViewerRemote\Tests\Support;

/**
 * Dựng file log giống hệt LineFormatter của Laravel (`allowInlineLineBreaks`,
 * `ignoreEmptyContextAndExtra`): `[dt] env.LEVEL: message %context% %extra%\n`, context
 * rỗng thành chuỗi rỗng (để lại dấu cách), `\n` trong JSON thành xuống dòng thật.
 */
final class LogBuilder
{
    private string $content = '';

    /** @var list<string> */
    private static array $files = [];

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $extra
     */
    public function entry(string $datetime, string $level, string $message, array $context = [], array $extra = [], string $env = 'production'): self
    {
        $this->content .= "[{$datetime}] {$env}.{$level}: {$message} ".self::json($context).' '.self::json($extra)."\n";

        return $this;
    }

    /** Byte thô, không qua formatter (CRLF, UTF-8 hỏng, thiếu xuống dòng cuối…). */
    public function raw(string $bytes): self
    {
        $this->content .= $bytes;

        return $this;
    }

    public function content(): string
    {
        return $this->content;
    }

    public function write(string $name = 'test.log'): string
    {
        $dir = sys_get_temp_dir().'/lvr-tests-'.getmypid();
        @mkdir($dir, 0777, true);
        $path = $dir.'/'.uniqid().'-'.$name;
        file_put_contents($path, $this->content);
        self::$files[] = $path;

        return $path;
    }

    public static function cleanup(): void
    {
        foreach (self::$files as $file) {
            @unlink($file);
        }

        self::$files = [];
    }

    /** @param array<string, mixed> $value */
    private static function json(array $value): string
    {
        if ($value === []) {
            return '';
        }

        $json = (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE);

        return (string) preg_replace('/(?<!\\\\)\\\\[rn]/', "\n", $json);
    }
}
