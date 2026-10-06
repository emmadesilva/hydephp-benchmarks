<?php

/*
 * Times Markdown conversion on its own, outside of any generator.
 *
 *   php benchmarks/markdown.php [posts=1000]
 *
 * Converts the same corpus four ways, in one process each so they cannot warm each other up:
 *
 *   hyde        Hyde's own Markdown::render(), which sets up a new converter for every page
 *   commonmark  league/commonmark with GFM, one converter reused for every page
 *   commonmark-new  the same, but a new converter for every page, like Hyde does
 *   php-markdown    michelf/php-markdown's MarkdownExtra, Jigsaw's default parser
 */

declare(strict_types=1);

namespace Bench;

require __DIR__.'/src/Corpus.php';
require __DIR__.'/src/Support.php';

$posts = (int) ($argv[1] ?? 1000);

if (isset($argv[2])) {
    exit(child($argv[2], $posts));
}

$results = [];
foreach (['hyde', 'commonmark', 'commonmark-new', 'php-markdown'] as $variant) {
    $output = shell_exec(sprintf('%s %s %d %s', PHP_BINARY, escapeshellarg(__FILE__), $posts, $variant));
    $results[$variant] = json_decode($output, true) ?? throw new \RuntimeException("$variant failed: $output");
    printf("%-15s %7.1f ms total  %6.3f ms/page\n", $variant, $results[$variant]['ms'], $results[$variant]['ms'] / $posts);
}

@mkdir(Paths::results(), recursive: true);
file_put_contents(Paths::results('markdown.json'), json_encode([
    'updated' => gmdate('c'),
    'machine' => Machine::describe(),
    'posts' => $posts,
    'results' => $results,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

function child(string $variant, int $count): int
{
    $bodies = array_map(fn (Post $post) => $post->body, iterator_to_array((new Corpus)->posts($count)));

    if ($variant === 'php-markdown') {
        require Paths::generators('jigsaw/vendor/autoload.php');
        $parser = new \Michelf\MarkdownExtra;
        $convert = fn (string $markdown) => $parser->transform($markdown);
    } elseif ($variant === 'hyde') {
        // Boot the Hyde project this repository is, so render() runs with its real configuration.
        // Laravel resolves some storage paths from the working directory, so run from the project root.
        chdir(Paths::root());
        require Paths::root('vendor/autoload.php');
        $app = require Paths::root('app/bootstrap.php');
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $convert = fn (string $markdown) => \Hyde\Markdown\Models\Markdown::render($markdown, \Hyde\Pages\MarkdownPost::class);
    } else {
        require Paths::root('vendor/autoload.php');
        $make = function () {
            $environment = new \League\CommonMark\Environment\Environment;
            $environment->addExtension(new \League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension);
            $environment->addExtension(new \League\CommonMark\Extension\GithubFlavoredMarkdownExtension);

            return new \League\CommonMark\MarkdownConverter($environment);
        };
        $shared = $make();
        $convert = $variant === 'commonmark'
            ? fn (string $markdown) => $shared->convert($markdown)->getContent()
            : fn (string $markdown) => $make()->convert($markdown)->getContent();
    }

    // Convert one post first, so class loading isn't counted as Markdown time.
    $convert($bodies[0]);

    $bytes = 0;
    $start = hrtime(true);
    foreach ($bodies as $body) {
        $bytes += strlen((string) $convert($body));
    }
    $ms = (hrtime(true) - $start) / 1e6;

    echo json_encode(['ms' => round($ms, 1), 'html_bytes' => $bytes]);

    return 0;
}
