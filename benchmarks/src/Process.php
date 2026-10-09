<?php

declare(strict_types=1);

namespace Bench;

/**
 * Runs a command and measures it from the outside.
 *
 * We fork and exec ourselves instead of using proc_open, because pcntl_waitpid() hands back
 * the child's rusage. That gives us CPU time and peak memory (max RSS) for exactly one
 * build, including any child processes it waited for, with no /usr/bin/time required.
 */
final class Process
{
    /**
     * @param  list<string>  $command  Executable followed by its arguments. No shell involved.
     * @param  array<string, string>  $env
     */
    public static function measure(array $command, string $cwd, array $env = [], ?string $log = null): Measurement
    {
        $log ??= '/dev/null';
        $binary = self::resolve($command[0]);
        $env = array_merge(self::baseEnv(), $env);

        $started = hrtime(true);
        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new \RuntimeException('Could not fork');
        }

        if ($pid === 0) {
            // Child: point stdout and stderr at the log, then become the build tool.
            chdir($cwd);
            fclose(STDOUT);
            fclose(STDERR);
            $stdout = fopen($log, 'w');
            $stderr = fopen($log, 'a');
            pcntl_exec($binary, array_slice($command, 1), $env);
            exit(127);
        }

        pcntl_waitpid($pid, $status, 0, $usage);
        $wall = (hrtime(true) - $started) / 1e9;

        return new Measurement(
            wall: $wall,
            user: $usage['ru_utime.tv_sec'] + $usage['ru_utime.tv_usec'] / 1e6,
            system: $usage['ru_stime.tv_sec'] + $usage['ru_stime.tv_usec'] / 1e6,
            maxRssMb: $usage['ru_maxrss'] / 1024, // Linux reports kilobytes
            exitCode: pcntl_wexitstatus($status),
        );
    }

    /** Runs a command and returns its output, for setup steps that are not being measured. */
    public static function run(array $command, string $cwd, array $env = []): string
    {
        $proc = proc_open($command, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $cwd, array_merge(self::baseEnv(), $env));
        $output = stream_get_contents($pipes[1]);
        $code = proc_close($proc);

        if ($code !== 0) {
            throw new \RuntimeException("Command failed ($code): ".implode(' ', $command)."\n".$output);
        }

        return $output;
    }

    private static function resolve(string $binary): string
    {
        if (str_contains($binary, '/')) {
            return $binary;
        }

        foreach (explode(':', getenv('PATH') ?: '/usr/bin:/bin') as $dir) {
            if (is_executable("$dir/$binary")) {
                return "$dir/$binary";
            }
        }

        throw new \RuntimeException("Could not find $binary on PATH");
    }

    /** @return array<string, string> */
    private static function baseEnv(): array
    {
        return array_filter(getenv(), fn ($value) => is_string($value));
    }
}

final class Measurement
{
    public function __construct(
        public readonly float $wall,
        public readonly float $user,
        public readonly float $system,
        public readonly float $maxRssMb,
        public readonly int $exitCode,
    ) {
    }

    /** CPU time across all threads. More than wall time means the tool used more than one core. */
    public function cpu(): float
    {
        return $this->user + $this->system;
    }

    public function toArray(): array
    {
        return [
            'wall' => round($this->wall, 4),
            'user' => round($this->user, 4),
            'system' => round($this->system, 4),
            'max_rss_mb' => round($this->maxRssMb, 1),
        ];
    }
}
