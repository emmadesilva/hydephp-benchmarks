<?php

declare(strict_types=1);

namespace Bench;

final class Paths
{
    /** The Hyde project this repository is. */
    public static function root(string $path = ''): string
    {
        return self::join(dirname(__DIR__, 2), $path);
    }

    public static function bench(string $path = ''): string
    {
        return self::join(dirname(__DIR__), $path);
    }

    public static function generators(string $path = ''): string
    {
        return self::join(self::bench('generators'), $path);
    }

    public static function templates(string $path = ''): string
    {
        return self::join(self::bench('templates'), $path);
    }

    public static function work(string $path = ''): string
    {
        return self::join(self::bench('work'), $path);
    }

    public static function results(string $path = ''): string
    {
        return self::join(self::bench('results'), $path);
    }

    private static function join(string $base, string $path): string
    {
        return $path === '' ? $base : $base.'/'.ltrim($path, '/');
    }
}

final class Fs
{
    public static function copy(string $from, string $to): void
    {
        if (is_file($from)) {
            @mkdir(dirname($to), recursive: true);
            copy($from, $to);

            return;
        }

        @mkdir($to, recursive: true);
        foreach (new \FilesystemIterator($from) as $item) {
            self::copy($item->getPathname(), $to.'/'.$item->getFilename());
        }
    }

    public static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
        } elseif (is_dir($path)) {
            self::removeContents($path, keepGitignore: false);
            rmdir($path);
        }
    }

    public static function removeContents(string $dir, bool $keepGitignore = true): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (new \FilesystemIterator($dir) as $item) {
            // Keep .gitignore files, so a Hyde project's storage directories stay intact.
            if (! $keepGitignore || $item->getFilename() !== '.gitignore') {
                self::remove($item->getPathname());
            }
        }
    }

    /** Removes a path, or with a trailing "/*" just what is inside it. */
    public static function clean(string $path): void
    {
        str_ends_with($path, '/*') ? self::removeContents(substr($path, 0, -2)) : self::remove($path);
    }

    /** @return array{html: int, files: int, bytes: int} */
    public static function stats(string $dir): array
    {
        $stats = ['html' => 0, 'files' => 0, 'bytes' => 0];
        if (! is_dir($dir)) {
            return $stats;
        }

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $stats['files']++;
            $stats['bytes'] += $file->getSize();
            $stats['html'] += str_ends_with($file->getFilename(), '.html') ? 1 : 0;
        }

        return $stats;
    }
}

final class Composer
{
    public static function version(string $project, string $package): string
    {
        $lock = json_decode(file_get_contents("$project/composer.lock"), true);
        foreach ($lock['packages'] as $entry) {
            if ($entry['name'] === $package) {
                return ltrim($entry['version'], 'v');
            }
        }

        throw new \RuntimeException("$package is not installed in $project");
    }
}

final class Stats
{
    /** @param list<float> $values */
    public static function median(array $values): float
    {
        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
    }

    /** Sample standard deviation. */
    public static function stddev(array $values): float
    {
        $count = count($values);
        if ($count < 2) {
            return 0.0;
        }
        $mean = array_sum($values) / $count;

        return sqrt(array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $values)) / ($count - 1));
    }

    /** @param list<array> $runs */
    public static function summarize(array $runs): array
    {
        $summary = [];
        foreach (['wall', 'user', 'system', 'max_rss_mb'] as $key) {
            $values = array_column($runs, $key);
            $summary[$key] = [
                'median' => round(self::median($values), 4),
                'min' => round(min($values), 4),
                'max' => round(max($values), 4),
                'stddev' => round(self::stddev($values), 4),
            ];
        }

        return $summary;
    }
}

final class Machine
{
    public static function describe(): array
    {
        preg_match('/model name\s*:\s*(.+)/', (string) @file_get_contents('/proc/cpuinfo'), $cpu);
        preg_match('/MemTotal:\s+(\d+)/', (string) @file_get_contents('/proc/meminfo'), $mem);

        return [
            'cpu' => $cpu[1] ?? php_uname('m'),
            'cores' => (int) trim((string) shell_exec('nproc')),
            'memory_gb' => isset($mem[1]) ? round($mem[1] / 1024 / 1024, 1) : null,
            'os' => php_uname('s').' '.php_uname('r'),
            'php' => PHP_VERSION,
            'node' => trim((string) shell_exec('node --version 2>/dev/null')),
            'ruby' => trim((string) shell_exec('ruby -e "print RUBY_VERSION" 2>/dev/null')),
        ];
    }
}
