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

The benchmark's `*-patched` cases load these two files from the `hyde` entry script before Composer's autoloader
would load the originals, so `vendor/` is never modified.
