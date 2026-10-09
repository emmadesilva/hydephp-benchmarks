<?php

declare(strict_types=1);

namespace Bench;

/**
 * An adapter knows how to lay out one generator's project and how to build it.
 *
 * Every adapter produces the same site: one HTML page per post using a minimal layout,
 * plus a home page that links to every post, newest first. The exception is the "hyde"
 * adapter, which measures Hyde exactly as it ships (full theme, RSS feed and sitemap).
 */
abstract class Adapter
{
    /** Extra arguments placed between `php` and the script, for testing PHP settings like OPcache. */
    public array $phpArgs = [];

    abstract public function name(): string;

    abstract public function version(): string;

    /** Writes the posts in whatever layout this generator expects. */
    abstract protected function writePosts(string $workspace, iterable $posts): void;

    /** @return list<string> */
    abstract public function command(string $workspace): array;

    abstract public function outputDir(string $workspace): string;

    /**
     * Paths wiped before every run. The output directory always, plus any cache that would let
     * a generator skip work on the next build. Every measured build is a cold, CI-style build.
     *
     * @return list<string>
     */
    public function cleanPaths(string $workspace): array
    {
        return [$this->outputDir($workspace)];
    }

    /** @return array<string, string> */
    public function env(string $workspace): array
    {
        return [];
    }

    /** The template directory under benchmarks/templates that is copied into the workspace. */
    protected function template(): string
    {
        return $this->name();
    }

    public function prepare(string $workspace, iterable $posts): void
    {
        Fs::remove($workspace);
        Fs::copy(Paths::templates($this->template()), $workspace);
        $this->writePosts($workspace, $posts);
    }

    protected function php(string ...$args): array
    {
        return [PHP_BINARY, ...$this->phpArgs, ...$args];
    }
}

/** Hyde, as it ships: the default theme, a post feed home page, RSS and a sitemap. */
class HydeAdapter extends Adapter
{
    /** The fixes for the two quadratic slowdowns, see benchmarks/patches/README.md. */
    public const PATCHES = ['DynamicMarkdownLinkProcessor.php', 'Features.php'];

    /** Frees each page's Markdown syntax tree right away, so the cycle collector has less to do. */
    public const TREE_PATCH = 'MarkdownService.php';

    public function __construct(public readonly bool $patched = false, public readonly bool $freeTree = false)
    {
    }

    public function name(): string
    {
        return 'hyde';
    }

    public function version(): string
    {
        return 'HydePHP '.Composer::version(Paths::root(), 'hyde/framework').($this->patched ? ' + patch' : '').($this->freeTree ? ' + tree fix' : '');
    }

    protected function template(): string
    {
        return 'hyde-default';
    }

    public function prepare(string $workspace, iterable $posts): void
    {
        Fs::remove($workspace);

        // Start from the Hyde project this repository *is*, and share its vendor directory.
        foreach (['app', 'config', 'resources', '_media', '_pages', 'hyde', 'composer.json'] as $path) {
            Fs::copy(Paths::root($path), "$workspace/$path");
        }
        Fs::removeContents("$workspace/app/storage/framework/views");
        symlink(Paths::root('vendor'), "$workspace/vendor");
        mkdir("$workspace/_posts");
        mkdir("$workspace/_docs");

        Fs::copy(Paths::templates($this->template()), $workspace);
        $this->writePosts($workspace, $posts);

        if ($this->patched) {
            // Load the fixed classes before Composer's autoloader would load the originals.
            $requires = '';
            foreach ([...self::PATCHES, ...($this->freeTree ? [self::TREE_PATCH] : [])] as $file) {
                Fs::copy(Paths::bench("patches/$file"), "$workspace/patches/$file");
                $requires .= "\nrequire __DIR__.'/patches/$file';";
            }
            $script = file_get_contents("$workspace/hyde");
            $script = preg_replace('/^(\$autoloader = require .+;)$/m', '$1'.$requires, $script, 1, $count);
            if ($count !== 1) {
                throw new \RuntimeException('Could not apply the patch to the hyde script');
            }
            file_put_contents("$workspace/hyde", $script);
        }
    }

    protected function writePosts(string $workspace, iterable $posts): void
    {
        foreach ($posts as $post) {
            file_put_contents("$workspace/_posts/{$post->slug}.md", $post->markdown());
        }
    }

    public function command(string $workspace): array
    {
        return $this->php('hyde', 'build', '--no-ansi', '--no-interaction');
    }

    public function env(string $workspace): array
    {
        // A real URL is what turns on the sitemap and RSS feed, as on any deployed site.
        return ['SITE_URL' => 'https://example.com'];
    }

    public function outputDir(string $workspace): string
    {
        return "$workspace/_site";
    }

    public function cleanPaths(string $workspace): array
    {
        // Compiled Blade views. Cleared so every run includes compiling the templates, like a fresh CI checkout.
        return [$this->outputDir($workspace), "$workspace/app/storage/framework/views/*"];
    }
}

/** Hyde with the same minimal layout as every other generator, and no RSS or sitemap. */
class HydeMinimalAdapter extends HydeAdapter
{
    public function name(): string
    {
        return 'hyde-minimal';
    }

    protected function template(): string
    {
        return 'hyde-minimal';
    }

    public function prepare(string $workspace, iterable $posts): void
    {
        parent::prepare($workspace, $posts);

        // Only build what the other generators build: the posts and a home page.
        unlink("$workspace/_pages/404.blade.php");
        Fs::removeContents("$workspace/_media");

        $config = file_get_contents("$workspace/config/hyde.php");
        $config = str_replace("'generate_sitemap' => true", "'generate_sitemap' => false", $config);
        $config = preg_replace("/('rss' => \[[^\]]*?'enabled' =>) true/", '$1 false', $config, 1, $count);
        if ($count !== 1) {
            throw new \RuntimeException('Could not disable the RSS feed in config/hyde.php');
        }
        file_put_contents("$workspace/config/hyde.php", $config);
    }
}

class JigsawAdapter extends Adapter
{
    /** Use league/commonmark, the parser Hyde uses, instead of Jigsaw's default michelf/php-markdown. */
    public function __construct(public readonly bool $commonmark = false)
    {
    }

    public function name(): string
    {
        return 'jigsaw';
    }

    public function version(): string
    {
        return 'Jigsaw '.Composer::version(Paths::generators('jigsaw'), 'tightenco/jigsaw').($this->commonmark ? ' (CommonMark)' : '');
    }

    public function prepare(string $workspace, iterable $posts): void
    {
        parent::prepare($workspace, $posts);
        symlink(Paths::generators('jigsaw/vendor'), "$workspace/vendor");

        if ($this->commonmark) {
            $config = str_replace("'title' => 'Bench',", "'title' => 'Bench',\n    'commonmark' => true,", file_get_contents("$workspace/config.php"));
            file_put_contents("$workspace/config.php", $config);
        }
    }

    protected function writePosts(string $workspace, iterable $posts): void
    {
        mkdir("$workspace/source/_posts", recursive: true);
        foreach ($posts as $post) {
            $extra = ['extends' => '_layouts.post', 'section' => 'content'];
            file_put_contents("$workspace/source/_posts/{$post->slug}.md", $post->markdown($extra));
        }
    }

    public function command(string $workspace): array
    {
        return $this->php('vendor/tightenco/jigsaw/jigsaw', 'build', '--pretty=false', '--no-ansi', '--no-interaction');
    }

    public function outputDir(string $workspace): string
    {
        return "$workspace/build_local";
    }

    public function cleanPaths(string $workspace): array
    {
        return [$this->outputDir($workspace), "$workspace/cache"];
    }
}

class SculpinAdapter extends Adapter
{
    public function name(): string
    {
        return 'sculpin';
    }

    public function version(): string
    {
        return 'Sculpin '.Composer::version(Paths::generators('sculpin'), 'sculpin/sculpin');
    }

    public function prepare(string $workspace, iterable $posts): void
    {
        parent::prepare($workspace, $posts);
        symlink(Paths::generators('sculpin/vendor'), "$workspace/vendor");
    }

    protected function writePosts(string $workspace, iterable $posts): void
    {
        mkdir("$workspace/source/_posts", recursive: true);
        foreach ($posts as $post) {
            // Sculpin reads the date from the filename, like Jekyll.
            $file = "$workspace/source/_posts/{$post->day()}-{$post->slug}.md";
            file_put_contents($file, $post->markdown(['layout' => 'post']));
        }
    }

    public function command(string $workspace): array
    {
        return $this->php('vendor/sculpin/sculpin/bin/sculpin', 'generate', '--env=prod', '--no-ansi', '--no-interaction', '--project-dir='.$workspace);
    }

    public function outputDir(string $workspace): string
    {
        return "$workspace/output_prod";
    }

    public function cleanPaths(string $workspace): array
    {
        return [$this->outputDir($workspace), "$workspace/var/cache"];
    }
}

class HugoAdapter extends Adapter
{
    public function name(): string
    {
        return 'hugo';
    }

    public function version(): string
    {
        preg_match('/v(\d+\.\d+\.\d+)/', Process::run([Paths::generators('hugo/hugo'), 'version'], Paths::generators()), $m);

        return 'Hugo '.$m[1];
    }

    protected function writePosts(string $workspace, iterable $posts): void
    {
        mkdir("$workspace/content/posts", recursive: true);
        foreach ($posts as $post) {
            file_put_contents("$workspace/content/posts/{$post->slug}.md", $post->markdown());
        }
    }

    public function command(string $workspace): array
    {
        return [Paths::generators('hugo/hugo'), '--quiet'];
    }

    public function outputDir(string $workspace): string
    {
        return "$workspace/public";
    }

    public function cleanPaths(string $workspace): array
    {
        return [$this->outputDir($workspace), "$workspace/resources", "$workspace/.hugo_build.lock"];
    }
}

class EleventyAdapter extends Adapter
{
    public function name(): string
    {
        return 'eleventy';
    }

    public function version(): string
    {
        $package = json_decode(file_get_contents(Paths::generators('eleventy/node_modules/@11ty/eleventy/package.json')), true);

        return 'Eleventy '.$package['version'];
    }

    protected function writePosts(string $workspace, iterable $posts): void
    {
        foreach ($posts as $post) {
            file_put_contents("$workspace/src/posts/{$post->slug}.md", $post->markdown());
        }
    }

    public function command(string $workspace): array
    {
        return ['node', Paths::generators('eleventy/node_modules/@11ty/eleventy/cmd.cjs'), '--quiet'];
    }

    public function outputDir(string $workspace): string
    {
        return "$workspace/_site";
    }

    public function cleanPaths(string $workspace): array
    {
        return [$this->outputDir($workspace), "$workspace/.cache"];
    }
}

class JekyllAdapter extends Adapter
{
    /** Keep Jekyll's on-disk Markdown cache between builds, as Jekyll does by default. */
    public function __construct(public readonly bool $diskCache = false)
    {
    }

    public function name(): string
    {
        return 'jekyll';
    }

    public function version(): string
    {
        $out = Process::run(['bundle', 'exec', 'jekyll', '--version'], Paths::generators('jekyll'), $this->env(''));

        return 'Jekyll '.trim(substr(trim($out), strlen('jekyll ')));
    }

    protected function writePosts(string $workspace, iterable $posts): void
    {
        mkdir("$workspace/_posts");
        foreach ($posts as $post) {
            file_put_contents("$workspace/_posts/{$post->day()}-{$post->slug}.md", $post->markdown(['layout' => 'post']));
        }
    }

    public function command(string $workspace): array
    {
        // Jekyll 4 caches converted Markdown on disk between builds. Turned off so it does the full job every run.
        return ['bundle', 'exec', 'jekyll', 'build', '--quiet', ...($this->diskCache ? [] : ['--disable-disk-cache'])];
    }

    public function env(string $workspace): array
    {
        return ['BUNDLE_GEMFILE' => Paths::generators('jekyll/Gemfile')];
    }

    public function outputDir(string $workspace): string
    {
        return "$workspace/_site";
    }

    public function cleanPaths(string $workspace): array
    {
        if ($this->diskCache) {
            return [$this->outputDir($workspace)];
        }

        return [$this->outputDir($workspace), "$workspace/.jekyll-cache", "$workspace/.jekyll-metadata"];
    }
}
