# Static site generator benchmarks

Build-time benchmarks for HydePHP and five other static site generators: Jigsaw, Sculpin, Hugo, Eleventy and Jekyll.
The write-up is in [`_posts/benchmarking-php-static-site-generators.md`](../_posts/benchmarking-php-static-site-generators.md),
and the latest numbers are in [`results/README.md`](results/README.md).

## What is measured

Each generator builds the same blog: N Markdown posts, rendered into one HTML page each, plus a home page that links
to every post, newest first. Every generator gets:

- **The same content.** `src/Corpus.php` generates posts from a fixed seed, so every run on every machine gets
  byte-identical Markdown: headings, paragraphs with inline formatting and links, lists, tables, block quotes, fenced
  code blocks, and a link back to an earlier post in about a third of them.
- **The same minimal layout.** A title, a byline and the post body. The templates for each generator are in
  `templates/`. They differ only in syntax.
- **The same job.** Taxonomy pages, RSS, sitemaps, 404 pages and build-time syntax highlighting are switched off
  wherever a generator does them by default, so nobody does extra work. The runner checks every build produced
  at least one page per post plus the home page.

There is one exception. The `hyde` and `hyde-patched` cases build Hyde **as it ships**: the default Tailwind
theme, a post feed home page, a 404 page, the RSS feed and the sitemap. That's what you get from
`composer create-project hyde/hyde`, so it's worth knowing what it costs.

Every measured build is a **cold build**. The output directory and every cache a generator keeps between builds are
deleted before each run: compiled Blade and Twig templates, Jekyll's Markdown cache (`--disable-disk-cache`), and
Hugo's resource cache. That matches a fresh CI checkout, which is where most static sites are built.

For each case the runner does one discarded warm-up build (to fill the OS file cache), then usually 5 measured builds.
The builds of every generator in a group are interleaved: run 1 of each, then run 2 of each, and so on. The machine
gets noisier over time, and this way every generator gets the same noise. Results report the median.

Each build is started with `pcntl_fork()` and `pcntl_exec()`, so `pcntl_waitpid()` can return that one process's
resource usage: wall time, user and system CPU time, and peak memory (max RSS, which includes any child processes
it waited for).

## Suites

| Suite | What it answers |
| --- | --- |
| `compare` | Every generator at 100, 1,000 and 10,000 posts |
| `scaling` | How Hyde's build time grows from 1 to 5,000 posts, before and after the fix |
| `scaling-large` | Patched Hyde and Jigsaw at 10,000, 20,000 and 40,000 posts |
| `content` | Short, medium and long posts at 1,000 posts, to see how much of a build is Markdown |
| `php` | OPcache and the JIT, for the three PHP generators |
| `single-core` | Every generator pinned to one core with `taskset`, to separate fast code from more cores |

`php markdown.php` times Markdown conversion on its own: Hyde's renderer, league/commonmark and michelf/php-markdown.
`php profile.php` profiles a build with [Excimer](https://www.mediawiki.org/wiki/Excimer), see the comment at the top
of the file.

## The Hyde patch

The benchmark turned up a bug in hyde/framework 2.0.3 that makes build time grow with the square of the number of
pages. [`patches/DynamicMarkdownLinkProcessor.php`](patches/DynamicMarkdownLinkProcessor.php) is a drop-in fix. The
`*-patched` cases load it before Composer's autoloader would load the original, so nothing in `vendor/` is modified.

[`patches/verify.php`](patches/verify.php) is a differential test. It runs the original and the patched class side by
side on every page of a site, and on the inputs from Hyde's own unit tests, and fails on the first difference.

## Running it

You need Linux or macOS, PHP 8.2+ with the `pcntl` extension, Composer, Node 18+, and Ruby 3+ with Bundler.

```bash
benchmarks/setup.sh                        # install every generator at the pinned version
php benchmarks/bench list                  # list the suites
php benchmarks/bench compare --posts=100   # run part of a suite
php benchmarks/bench compare --only=hyde-minimal-patched,jigsaw --runs=3
benchmarks/run-all.sh                      # everything, takes a few hours
php benchmarks/report                      # write results/README.md and the charts
```

Close everything else while it runs, and don't run two suites at the same time.

## Caveats

- These numbers come from one 4-core cloud VM. Absolute times on your laptop will be different. The ratios between
  generators are what to look at, and they held steady across every run made while writing this.
- Every generator could be made faster with configuration this benchmark doesn't use. The point is to compare
  what you get without tuning.
- Generators differ in how much they do for you. A minimal layout is the fairest comparison of the build itself,
  but it isn't what anyone ships, which is why Hyde's default theme is measured too.
