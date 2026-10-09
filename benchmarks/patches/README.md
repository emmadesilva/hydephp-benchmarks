# Fixes for hyde/framework 2.0.3

Benchmarking turned up four things worth changing in Hyde. All four are still in the `2.x` and `master` branches
as of this writing, and each patch below applies cleanly to `2.x` on its own or together with the others. The paths
in the patches are relative to the framework package, so in the monorepo apply them from `packages/framework`:

```bash
cd packages/framework
patch -p1 < hyde-framework-2.0.3.patch
```

| Patch | What it fixes | Effect |
| --- | --- | --- |
| [`hyde-framework-2.0.3.patch`](hyde-framework-2.0.3.patch) | Two places that do work for every page while compiling each page (1 and 2 below) | Build time stops growing with the square of the page count. 10,000 posts with the default theme: 61 min → 2 min 12 s |
| [`free-syntax-tree.patch`](free-syntax-tree.patch) | Each page's Markdown syntax tree is left behind as circular garbage (3) | Half the garbage collector work. 10,000 posts: 11–16% faster, more on bigger sites |
| [`unquoted-yaml-date.patch`](unquoted-yaml-date.patch) | `date: 2026-10-06` without quotes crashes the build (4) | Not a performance fix |

## 1. `DynamicMarkdownLinkProcessor::postprocess()`

Hyde lets you link to a page by its source file, like `[About](_pages/about.md)`, and rewrites the link to the real
URL. To do that it runs this on every Markdown page it compiles:

```php
foreach (static::routeMap() as $sourcePath => $route) {   // every route on the site
    $patterns = [
        sprintf('<a href="%s"', $sourcePath),
        sprintf('<a href="/%s"', $sourcePath),
    ];

    $html = str_replace($patterns, sprintf('<a href="%s"', $route->getLink()), $html);
}
```

`routeMap()` is rebuilt every call, and `getLink()` resolves a relative URL through the config repository, once per
route, whether or not the page links there. With 1,000 posts that is a million `getLink()` calls and two million
`str_replace()` passes over page HTML. At 1,000 posts it was two thirds of the build.

[`DynamicMarkdownLinkProcessor.php`](DynamicMarkdownLinkProcessor.php) finds the links that are actually in the HTML
with one regex and looks each up in a source path map. The map is built once per route collection, and rebuilt if
routes are added later.

## 2. `Features::hasRss()`

The default theme's `<head>` asks `Features::hasRss()` on every page, to decide whether to add the
`<link rel="alternate">` tag for the feed. The last condition was:

```php
&& count(MarkdownPost::files()) > 0;
```

`MarkdownPost::files()` filters every source file on the site and then turns every post's path into an identifier,
just so the result can be counted and compared to zero. [`Features.php`](Features.php) asks whether any post exists,
which stops at the first one:

```php
&& Hyde::files()->contains(fn (SourceFile $file): bool => $file->pageClass === MarkdownPost::class);
```

## 3. Freeing the Markdown syntax tree

With 1 and 2 fixed, very large sites still got a little slower per page as they grew: 9.3 ms per post at 10,000 posts,
16.5 ms at 40,000. `gc_status()` showed why. At 20,000 posts, PHP's cycle collector ran 989 times and spent 96 seconds,
43% of the build. Everything else was linear.

league/commonmark's syntax tree links every node to its parent and its siblings, so when a page is done its whole tree
is one big reference cycle. Reference counting can't free a cycle, so each page leaves several hundred objects for the
cycle collector, which runs every time its buffer fills up. Each run also walks the live heap, and a site generator's
heap holds every page on the site, so the runs get slower as the build goes on. Jigsaw with its default php-markdown
parser (no syntax tree) ran the collector 8 times for 10,000 posts. Switched to CommonMark, 264 times.

[`MarkdownService.php`](MarkdownService.php) takes the tree apart once the HTML has been rendered, so reference counting
frees it immediately:

| Hyde with fixes 1 and 2, minimal layout | 10,000 posts | 20,000 posts |
| --- | ---: | ---: |
| Collector runs | 498 → 239 | 989 → 472 |
| Time in the collector | 26 s → 13.5 s | 96 s → 48 s |
| Total build time | 88.6 s → 75.3 s | 224 s → 171 s |

Those are single builds, measured with `gc_status()`. The `tree-compare` suite measured it properly at 10,000 posts,
three interleaved builds each:

| 10,000 posts | Fixes 1 and 2 | Plus fix 3 | Faster by |
| --- | ---: | ---: | ---: |
| Minimal layout | 89.4 s | 75.5 s | 16% |
| Default theme | 135.1 s | 120.5 s | 11% |

Peak memory (RSS) is unchanged, 221 MB against 223 MB at 10,000 posts.

### Not patched: one converter per page

Most of the garbage that's left, about 550 of 650 objects per page, comes from `MarkdownService` building a new
CommonMark environment for every page. An environment and its extensions point at each other. Reusing one converter
per page type would remove that, plus about 0.35 ms of setup per page. It isn't a drop-in change, though.
`HeadingRenderer` is created with a per-page registry of heading IDs, which it uses to avoid duplicate anchors, so a
shared converter would need that state reset for every page. That's a design decision, so there's no patch for it here.

## 4. Unquoted dates in front matter

Not a performance bug, but the benchmark's own blog post found it. This front matter crashes the whole build:

```yaml
date: 2026-10-06
```

```
An error occurred during the discovery process: Hyde\Support\Models\DateString::__construct(): Argument #1 ($string)
must be of type string, int given, called in .../BlogPostDataFactory.php on line 88
```

Symfony's YAML parser turns an unquoted date into a Unix timestamp, and `BlogPostDataFactory::makeDate()` hands that
straight to `DateString`, which only takes strings. Quoting the date works around it. The patch formats an integer
back into a date string first. Checked with `date: 2026-10-06`, `date: 2026-10-04 09:15:00` and
`date: "2026-10-05 14:30"`, which all render the expected date. The 204 tests in the six test files that cover dates
and post parsing pass.

## Checking the performance fixes

**Full site output.** The same 1,000-post site, built with Hyde's default theme by stock 2.0.3 and with patches 1–3,
produces the same 1,009 files. The only differences are the build timestamps in `feed.xml` (`<lastBuildDate>`) and
`sitemap.xml` (`<lastmod>`).

**A differential test.** [`verify.php`](verify.php) runs from any prepared Hyde workspace (`php bench` creates them
under `benchmarks/work`). It feeds the original and the patched link processor the same HTML from the point of view
of every page on the site: the inputs from Hyde's own `DynamicMarkdownLinkProcessorTest`, every page's real HTML, and
a link to every route and media file, with and without a leading slash. It fails on the first difference. Then it
checks the changed `hasRss()` condition gives the same answer as before, with and without posts.

```bash
cd benchmarks/work/compare/hyde-patched-1000-medium
php ../../../patches/verify.php
# DynamicMarkdownLinkProcessor OK: 13,026 comparisons across 1002 pages, outputs identical.
# Features::hasRss() OK: the patched condition agrees with and without posts.
```

**Hyde's own tests.** Each test file in hyde/framework 2.0.3 that touches the changed code was run twice, stock and
patched, from a clean copy of the project every time. The results were identical:

- Patches 1 and 2: 293 tests in 17 files (the link processor, features, RSS, sitemap and metadata tests).
- Patch 3: 431 tests in 19 files (everything that renders Markdown). `ConfigurableFeaturesUnitTest` fails the same
  way with and without the patch, with zero assertions.

`MarkdownPostUnitTest` doesn't load with the `hyde/testing` version on Packagist, stock or patched.

## Side effects

- **Output:** byte-identical, see above.
- **Memory:** patch 1's source path map adds about 4 MB at 10,000 posts. Patch 3 doesn't change peak memory.
- **Edge cases, in theory:**
  - The route map is cached for the whole build and rebuilt when the number of routes changes. If something replaced
    a route with a *different* page under the *same* route key halfway through a build, the map would be stale.
    Nothing in Hyde does that. Extensions add their routes when the kernel boots, before any page compiles.
  - The original ran one `str_replace()` per route in sequence, so a link it had just rewritten could in theory be
    rewritten again by a later route. The fix rewrites each link once. That would need a page's output link to be
    another page's source path, like `_pages/foo.md`, which Hyde never produces.
  - The media file map used to be built on the first page compiled. Now it's built on the first page that has an
    image. Both are cached for the rest of the process, exactly as before.
  - If PCRE fails on some enormous page (it shouldn't, the pattern can't backtrack), the page is returned unchanged
    instead of throwing.
  - Patch 3 empties the syntax tree after rendering. Anything that holds on to the `RenderedContent` returned from
    the converter, or to nodes from it, would find them detached. Nothing in Hyde or its bundled extensions does.
    Torchlight reads the tree while parsing, before rendering.
- **Faster in the small, too.** A 100-post site with the default theme went from 1.27 s to 1.00 s.

The benchmark's `*-patched` cases load patches 1 and 2, and the `*-tree` cases add patch 3. They're loaded from the
`hyde` entry script before Composer's autoloader would load the originals, so `vendor/` is never modified.
