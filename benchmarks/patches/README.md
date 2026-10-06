# Fixes for hyde/framework 2.0.3

Benchmarking turned up two places where Hyde does work for every page on the site while it compiles each page.
That makes build time grow with the square of the page count. Both are still in the `2.x` and `master` branches as
of this writing. [`hyde-framework-2.0.3.patch`](hyde-framework-2.0.3.patch) applies cleanly to both and contains
both fixes.

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

## Checking them

[`verify.php`](verify.php) is a differential test. Run it from any prepared Hyde workspace (`php bench` creates
them under `benchmarks/work`). It feeds the original and the patched link processor the same HTML from the point
of view of every page on the site: the inputs from Hyde's own `DynamicMarkdownLinkProcessorTest`, every page's
real HTML, and a link to every route and media file, with and without a leading slash. It fails on the first
difference. Then it checks the changed `hasRss()` condition gives the same answer as before, with and without posts.

```bash
cd benchmarks/work/compare/hyde-patched-1000-medium
php ../../../patches/verify.php
# DynamicMarkdownLinkProcessor OK: 13,026 comparisons across 1002 pages, outputs identical.
# Features::hasRss() OK: the patched condition agrees with and without posts.
```

Hyde's own tests agree. Every test file in hyde/framework 2.0.3 that touches either class, or the RSS feed, sitemap
and metadata code around them, was run against stock 2.0.3 and then with both patches, from a clean project each
time: 293 tests in 17 files, same results both ways. (One more file, `MarkdownPostUnitTest`, doesn't load with the
`hyde/testing` version on Packagist, stock or patched.)

## Side effects

- **Output:** none found, see above. The fixed build writes byte-identical pages.
- **Memory:** the source path map adds a few MB at most: 221 MB versus 217 MB peak at 10,000 posts.
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
- **Faster in the small, too.** A 100-post site with the default theme went from 1.27 s to 1.00 s.

The benchmark's `*-patched` cases load these two files from the `hyde` entry script before Composer's autoloader
would load the originals, so `vendor/` is never modified.
