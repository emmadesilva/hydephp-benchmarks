<?php

/*
 * Profiles a build with the Excimer sampling profiler (https://www.mediawiki.org/wiki/Excimer).
 *
 *   cd benchmarks/work/compare/hyde-minimal-1000-medium
 *   php -d extension=excimer.so ../../../profile.php hyde build
 *
 * Prints the functions with the highest inclusive ("time spent in or below") and exclusive
 * ("time spent in this function itself") sample counts, and writes collapsed stacks to
 * profile.folded, which flamegraph.pl or speedscope.app can render.
 */

if (! extension_loaded('excimer')) {
    fwrite(STDERR, "The excimer extension is required, see the comment at the top of this file.\n");
    exit(1);
}

$script = $argv[1] ?? 'hyde';
$_SERVER['argv'] = $argv = array_slice($argv, 1);
$_SERVER['argc'] = $argc = count($argv);

$profiler = new ExcimerProfiler;
$profiler->setPeriod(0.001);
$profiler->setEventType(EXCIMER_CPU);
$profiler->start();

register_shutdown_function(function () use ($profiler) {
    $profiler->stop();
    $log = $profiler->getLog();
    file_put_contents('profile.folded', $log->formatCollapsed());

    $total = count($log);
    $inclusive = [];
    $exclusive = [];
    foreach ($log as $entry) {
        $seen = [];
        foreach ($entry->getTrace() as $depth => $frame) {
            $name = isset($frame['class'], $frame['function']) ? $frame['class'].'::'.$frame['function'] : ($frame['function'] ?? basename($frame['file'] ?? '?'));
            if ($depth === 0) {
                $exclusive[$name] = ($exclusive[$name] ?? 0) + 1;
            }
            if (! isset($seen[$name])) {
                $inclusive[$name] = ($inclusive[$name] ?? 0) + 1;
                $seen[$name] = true;
            }
        }
    }

    arsort($inclusive);
    arsort($exclusive);
    $print = function (string $title, array $counts) use ($total) {
        fwrite(STDERR, "\n$title\n");
        foreach (array_slice($counts, 0, 25, true) as $name => $count) {
            fwrite(STDERR, sprintf("  %5.1f%%  %s\n", $count / $total * 100, $name));
        }
    };
    fwrite(STDERR, "\n$total samples (1 per ms of CPU time)\n");
    $print('Inclusive', $inclusive);
    $print('Exclusive', $exclusive);
});

require getcwd().'/'.$script;
