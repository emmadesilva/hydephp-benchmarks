<?php

declare(strict_types=1);

/*
 * The benchmark suites. Each suite is a list of cases, and each case is one thing we time.
 *
 *   label     How the case shows up in results and charts
 *   adapter   Which generator, see src/Adapters.php
 *   args      Constructor arguments for the adapter, for variants like the patched Hyde
 *   posts     How many posts the corpus has
 *   content   Post length: short (~150 words), medium (~600 words) or long (~2,000 words)
 *   php       Extra `php -d` flags, for the PHP-only cases
 *   cpus      Pin the build to this many CPU cores with taskset (default: all of them)
 *   runs      Measured runs (default 5)
 *   warmup    Do one discarded run first (default true)
 */

use Bench\EleventyAdapter;
use Bench\HugoAdapter;
use Bench\HydeAdapter;
use Bench\HydeMinimalAdapter;
use Bench\JekyllAdapter;
use Bench\JigsawAdapter;
use Bench\SculpinAdapter;

$generators = [
    'hyde' => [HydeAdapter::class],
    'hyde-patched' => [HydeAdapter::class, ['patched' => true]],
    'hyde-minimal' => [HydeMinimalAdapter::class],
    'hyde-minimal-patched' => [HydeMinimalAdapter::class, ['patched' => true]],
    'jigsaw' => [JigsawAdapter::class],
    'jigsaw-commonmark' => [JigsawAdapter::class, ['commonmark' => true]],
    'sculpin' => [SculpinAdapter::class],
    'hugo' => [HugoAdapter::class],
    'eleventy' => [EleventyAdapter::class],
    'jekyll' => [JekyllAdapter::class],
];

// Builds that take long enough at this size that one run is all we can reasonably afford.
$slow = fn (string $label, int $posts) => in_array($label, ['hyde', 'hyde-minimal'], true) && $posts >= 2500;

$cases = fn (array $labels, array $sizes, array $extra = []) => array_merge(...array_map(
    fn (int $posts) => array_map(fn (string $label) => [
        'label' => $label,
        'adapter' => $generators[$label][0],
        'args' => $generators[$label][1] ?? [],
        'posts' => $posts,
    ] + ($slow($label, $posts) ? ['runs' => 1, 'warmup' => false] : []) + $extra, $labels),
    $sizes,
));

$opcache = ['-d', 'opcache.enable_cli=1'];
$jit = [...$opcache, '-d', 'opcache.jit=tracing', '-d', 'opcache.jit_buffer_size=128M'];

$php = fn (string $label, int $posts) => [
    ['label' => $label, 'adapter' => $generators[$label][0], 'args' => $generators[$label][1] ?? [], 'posts' => $posts],
    ['label' => "$label +opcache", 'adapter' => $generators[$label][0], 'args' => $generators[$label][1] ?? [], 'posts' => $posts, 'php' => $opcache],
    ['label' => "$label +jit", 'adapter' => $generators[$label][0], 'args' => $generators[$label][1] ?? [], 'posts' => $posts, 'php' => $jit],
];

return [
    // The headline comparison: every generator, same posts, same minimal layout.
    'compare' => $cases(array_keys($generators), [100, 1000, 10000]),

    // How Hyde's build time grows with the number of posts, before and after the fix.
    'scaling' => $cases(
        ['hyde-minimal', 'hyde-minimal-patched', 'hyde-patched'],
        [1, 10, 100, 250, 500, 1000, 2500, 5000],
        ['runs' => 3],
    ),

    // Patched Hyde is fast enough to go much further.
    'scaling-large' => [
        ...$cases(['hyde-minimal-patched', 'hyde-patched', 'jigsaw'], [10000, 20000], ['runs' => 3]),
        ...$cases(['hyde-minimal-patched', 'hyde-patched', 'jigsaw'], [40000], ['runs' => 1]),
    ],

    // Same page count, different post lengths: how much of the work is Markdown?
    'content' => array_merge(...array_map(
        fn (string $length) => $cases(
            ['hyde-minimal-patched', 'jigsaw', 'jigsaw-commonmark', 'sculpin', 'hugo', 'eleventy', 'jekyll'],
            [1000],
            ['content' => $length, 'runs' => 3],
        ),
        ['short', 'medium', 'long'],
    )),

    // PHP runtime settings. OPcache is off for the CLI by default, and JIT needs OPcache.
    'php' => array_merge(
        ...array_map(fn (int $posts) => [...$php('hyde-minimal-patched', $posts), ...$php('jigsaw', $posts), ...$php('sculpin', $posts)], [100, 5000]),
    ),

    // Every generator pinned to a single core, to separate "faster code" from "more cores".
    'single-core' => $cases(
        ['hyde-minimal-patched', 'jigsaw', 'sculpin', 'hugo', 'eleventy', 'jekyll'],
        [10000],
        ['cpus' => 1, 'runs' => 3],
    ),
];
