---
title: Why you should benchmark your code, even when you don't need to micro-optimize
description: I benchmarked Hyde against five other static site generators out of curiosity, and found a quadratic slowdown that small sites never show. Here's the process, the fix, and the numbers.
category: engineering
author: Emma
date: "2026-10-06 12:00"
---

Build speed has never been something I worry about much in Hyde. It's always been fast enough. When I run a build on
one of my own sites, it finishes in a second or two, and the output says something like a few milliseconds per page.
So when I make decisions about Hyde, I make them for the developer experience: nicer APIs, better defaults, less
config. Shaving microseconds off a build that already finishes before you've looked back at your editor just isn't
where the value is.

But while writing [Why You Should Use a PHP Static Site Generator](why-you-should-use-a-php-static-site-generator.html),
I typed this sentence:

> In Hyde, each page builds in a few milliseconds, so even sites with tens of thousands of pages are no problem.

And I realised I hadn't actually checked that in ages. My only reference was those small sites. The milliseconds-per-page
part I'd seen with my own eyes. The "tens of thousands of pages" part was an assumption: if each page takes a few
milliseconds, ten thousand pages take ten thousand times that. Hyde builds pages one after the other, so it should
scale linearly. Right?

So, mostly out of curiosity, I built a proper benchmark. It compares Hyde with Jigsaw and Sculpin, the other two PHP
generators, and with Hugo, Eleventy and Jekyll as reference points, from one post up to forty thousand. Everything is in
a [public repository](https://github.com/emmadesilva/hydephp-benchmarks), including the raw results, so you can check
my work or run it on your own machine.

It turns out the assumption was wrong. Hyde 2.0.3 took **over an hour** to build 10,000 blog posts. Not because each
page is slow, but because of two small bugs that made every page do a little bit of work *for every other page on the
site*. On a small site you'd never notice. On a big one, it's the whole build. Fixing them took a few lines of code
and brought that hour down to about two minutes.

That's really what this post is about. Benchmarking isn't only for people chasing microseconds. Even if you never
plan to optimise anything, it's the cheapest way to find out whether your code behaves the way you *think* it does.

## Making the comparison fair

Benchmarking static site generators against each other is easy to do badly. They don't do the same work out of the
box. Hugo makes tag pages, an RSS feed and a sitemap. Jekyll highlights code blocks. Hyde ships a full Tailwind theme.
If you time each one's defaults, you're mostly timing their feature lists.

So every generator builds the same site:

- **The same posts.** A generator script writes the Markdown from a fixed random seed, so every run gets byte-identical
  content: headings, paragraphs with bold text, inline code and links, lists, tables, quotes, fenced code blocks, and
  a link back to an earlier post in about a third of them. Medium posts are about 600 words.
- **The same minimal layout.** A title, a byline and the post body, written once in each generator's template
  language: Blade, Twig, Go templates, Liquid. Plus a home page that lists every post, newest first.
- **The same job.** Tag pages, feeds, sitemaps, 404 pages and build-time syntax highlighting are turned off wherever
  they're on by default. After every build, the runner checks that it really produced one page per post.

I also measure Hyde exactly as it ships, with its default theme, RSS feed and sitemap, because that's what you actually
get from `composer create-project`.

Every build is a cold build. Before each run the output and every cache are deleted: compiled Blade and Twig templates,
Hugo's resource cache, and Jekyll's Markdown cache. That's what happens in CI, which is where most static sites get
built. One detail surprised me here: Jekyll 4 caches converted Markdown on disk between builds. If you don't pass
`--disable-disk-cache`, every run after the first quietly skips most of the work, and Jekyll looks three
times faster than it is (2.5 seconds instead of 7.7 for 1,000 posts).

The runner itself is PHP. It starts each build with `pcntl_fork()` and `pcntl_exec()` instead of `proc_open()`,
because then `pcntl_waitpid()` returns the child's resource usage: CPU time and peak memory for exactly that one build,
without needing `/usr/bin/time`. Each case gets one discarded warm-up build and then five measured ones, interleaved
across generators (run 1 of everything, then run 2 of everything). Cloud machines slow down and speed up as the
neighbours come and go, and interleaving means every generator gets the same weather.

The machine is a 4-core cloud VM with PHP 8.3, Node 22 and Ruby 3.3. Your laptop will be faster or slower, but the
ratios between generators are what matter, and those held steady across runs.

## The first numbers didn't add up

The very first smoke test, at 100 posts, looked fine. Hyde was slower than Jigsaw, but within reason. Then I tried
1,000 posts:

| Posts | Hyde 2.0.3 | Jigsaw |
| ---: | ---: | ---: |
| 100 | 0.78 s | 0.32 s |
| 1,000 | 17.9 s | 2.19 s |

Ten times the posts took Jigsaw about seven times as long, which is normal: there's a fixed startup cost that stops
mattering as the site grows. Ten times the posts took Hyde *twenty-three times* as long. Per post, the cost had nearly
tripled. That's the signature of something quadratic: some work that grows with the number of pages, done once for
every page.

![Hyde build time from 1 to 5,000 posts, before and after the fix](_media/benchmarks/scaling.svg)

At 5,000 posts Hyde 2.0.3 took more than five minutes. At 10,000 it took 21 minutes with the minimal layout and
**61 minutes** with the default theme.

## Finding it

I built the [Excimer](https://www.mediawiki.org/wiki/Excimer) sampling profiler as a PHP extension and wrote a little
wrapper that profiles a whole `php hyde build`. The top of the list was immediate:

```
Exclusive
   15.3%  Hyde\Markdown\Processing\DynamicMarkdownLinkProcessor::postprocess
    5.0%  Illuminate\Support\Arr::exists
    4.6%  Illuminate\Support\Arr::get
    4.6%  Illuminate\Support\Facades\Facade::__callStatic
    3.4%  Hyde\unslash
    3.3%  Hyde\Facades\Config::validated
```

Hyde lets you link to another page by its source file, like `[About](_pages/about.md)`, and turns that into the right
URL when it builds. This is the code that does it, which runs on the HTML of every Markdown page:

```php
foreach (static::routeMap() as $sourcePath => $route) {
    $patterns = [
        sprintf('<a href="%s"', $sourcePath),
        sprintf('<a href="/%s"', $sourcePath),
    ];

    $html = str_replace($patterns, sprintf('<a href="%s"', $route->getLink()), $html);
}
```

For every page, it loops over **every route on the site**, works out that route's relative link (which goes through
the config repository, hence all the `Arr::get` calls), and scans the page's HTML for it. It does this whether or not
the page links anywhere. On a blog with 1,000 posts that's a million link resolutions and two million passes over HTML
to rewrite, typically, zero links.

The fix is to turn it around. Find the links that are actually in the HTML with one regex, then look each one up in a
map from source path to route, built once per build instead of once per page:

```php
return preg_replace_callback('/<(a href|img src)="\/?([^"]*)"/', function (array $matches): string {
    [$tag, $attribute, $path] = $matches;

    if ($attribute === 'a href') {
        $route = static::routeMap()[$path] ?? null;

        return $route ? sprintf('<a href="%s"', $route->getLink()) : $tag;
    }

    // ...the same for images
}, $html);
```

That fixed the minimal layout. The default theme was still curving upwards, so back to the profiler, which found the
second one. The theme's `<head>` asks `Features::hasRss()` on every page, to decide whether to add a `<link>` tag for
the feed. The last thing that method checked was:

```php
&& count(MarkdownPost::files()) > 0;
```

That lists every post on the site, converts each file path to an identifier, and counts the result, just to find out
whether there's at least one. Once per page. The fix stops at the first post it finds:

```php
&& Hyde::files()->contains(fn (SourceFile $file): bool => $file->pageClass === MarkdownPost::class);
```

## Proving the fix doesn't break anything

A faster function that returns different HTML is not a fix. Unit tests only check the cases somebody thought of, so I
also wrote a differential test. It boots a real Hyde site, loads the patched class under a different name next to the
original, and feeds both the same HTML from the point of view of every page on the site. The inputs are every case from Hyde's own unit tests for this class, the real HTML of every
page, and a link to every route and media file, with and without a leading slash.

On a 1,002-page site that's 13,026 comparisons, and every output was identical. To make sure the test could actually
fail, I broke the patch on purpose (I dropped the leading-slash handling) and it caught it on the first page.

Then I ran every test file in Hyde's own suite that touches the two changed classes, or the feed, sitemap and metadata
code around them, once on stock 2.0.3 and once patched: 293 tests, the same results both ways. {{BYTE_IDENTICAL}}

One thing that bit me while doing that: my first patched run had four failures, and for a minute I thought I'd broken
image links. I hadn't. An earlier test run had failed halfway through and left a renamed file behind in my scratch
project, and the next run tripped over it. Resetting the project before every test file made the failures disappear on
both versions. If a "regression" only shows up in the second run, suspect the leftovers from the first.

## After the fix

![Time per post as the blog grows, before and after the fix](_media/benchmarks/per-post.svg)

The orange line is what quadratic looks like when you divide by the number of posts. The blue line is what it's meant
to look like. On a real site the difference is large well before you get to tens of thousands of pages:

| Posts | Hyde 2.0.3, default theme | Hyde with the fixes | Faster by |
| ---: | ---: | ---: | ---: |
| 100 | 1.27 s | 1.00 s | 1.3× |
| 1,000 | 31.6 s | 8.79 s | 3.6× |
| 10,000 | 61 min 43 s | 2 min 12 s | 28× |

Both fixes are a few lines each, and the patch applies cleanly to Hyde's current `2.x` branch.

## How everyone compares

With Hyde fixed, here's every generator building the same 10,000-post site:

![Build time for 10,000 blog posts, every generator](_media/benchmarks/compare-10000.svg)

| Generator | 100 posts | 1,000 posts | 10,000 posts | Peak memory at 10,000 |
| --- | ---: | ---: | ---: | ---: |
| Hugo 0.167 | 0.14 s | 0.86 s | 6.1 s | 1,064 MB |
| Jigsaw 1.8.8 | 0.32 s | 2.19 s | 22.9 s | 274 MB |
| Eleventy 3.1.6 | 0.85 s | 2.98 s | 24.7 s | 1,148 MB |
| Sculpin 3.3.1 | 0.51 s | 4.20 s | 50.0 s | 775 MB |
| Jekyll 4.4.1 | 1.76 s | 6.41 s | 57.6 s | 367 MB |
| Hyde with the fixes | 0.64 s | 5.56 s | 83.1 s | 221 MB |
| Hyde 2.0.3 | 0.78 s | 17.9 s | 21 min 22 s | 217 MB |

A few things stand out.

**Hugo is in a different league,** and it isn't only because Go is compiled. Hugo used {{HUGO_CPU}} seconds of CPU time
for a 6-second build: it spreads the work across every core. Pinned to a single core, it {{HUGO_SINGLE_CORE}}. All
three PHP generators run on one core, so on a 4-core machine they leave three quarters of it idle.

**Jigsaw is the fastest PHP generator, by a lot.** About 2.3 ms per post against Hyde's 6–8 ms. That's the gap I most
wanted to understand, because Jigsaw and Hyde are built from the same Laravel pieces.

**Memory tells a different story to speed.** The PHP generators stay small. Hyde peaked at 221 MB for 10,000 posts, while
Hugo and Eleventy both went past a gigabyte. That's rarely a problem on your own machine, but it's the difference
between fitting in a small CI runner or not.

**Small sites are mostly startup.** At 100 posts, Eleventy spends most of its 0.85 seconds starting Node and loading
modules, and Jekyll spends most of its 1.76 seconds in Bundler. Hyde needs about 0.2 seconds to boot Laravel Zero
before it touches the first page. Below a few hundred pages, nobody will notice any of this.

## Where Hyde's time goes now

Profiling the fixed build shows where the remaining gap to Jigsaw is:

{{PROFILE_TABLE}}

Half of a Hyde build is the Markdown parser. Hyde uses [league/commonmark](https://commonmark.thephpleague.com/), which
follows the CommonMark spec to the letter. Jigsaw uses michelf/php-markdown by default, which is older and less
strict, but much faster. I timed the parsers on their own with the same posts:

{{MARKDOWN_TABLE}}

Jigsaw can switch to CommonMark with one config option, so I measured that as well. It goes from 22.9 to 48.7 seconds
at 10,000 posts. More than half of the gap between Hyde and Jigsaw is just the choice of Markdown parser.

Two Hyde-specific costs show up too, and both are easy to remove:

- **Every heading is rendered through a Blade component**, so Hyde can add permalink anchors to headings. It does that
  even for blog posts, where permalinks are switched off by default. That's {{HEADING_SHARE}} of the build.
- **Hyde builds a new Markdown converter for every page.** Building the CommonMark environment and registering its
  extensions costs about a third of a millisecond each time. As the next section shows, that's the smaller half of what
  it costs.

## The last bit of the curve

With both bugs fixed, the line was nearly flat, but not quite. At 40,000 posts the fixed build took 16.5 ms per post,
against 9.3 ms at 10,000. Jigsaw stayed close to flat over the same range. So something was still growing with the
size of the site.

My first guess was PHP's garbage collector, and my first test of that guess said no: turning the collector off
(`zend.enable_gc=0`) made a 2,500-post build 13% *slower* and pushed its memory from 96 MB to 364 MB. So I moved on.
That was the wrong conclusion. The test had only shown that switching the collector off isn't the fix. It said nothing
about how much time the collector was taking.

PHP 8.3's `gc_status()` reports exactly that, so I printed it at the end of a build:

| Hyde with both fixes, minimal layout | Collector runs | Time in the collector | Everything else, per post |
| ---: | ---: | ---: | ---: |
| 10,000 posts | 498 | 26 s (30%) | 6.2 ms |
| 20,000 posts | 989 | 96 s (43%) | 6.4 ms |

Everything except the collector was linear. The collector runs about once every 20 pages, which is also linear, but
each run walks the live heap, and in a site generator the live heap holds every page on the site. Each run costs more
than the last. That's quadratic again, just better hidden.

The garbage comes from the Markdown parser. In league/commonmark's syntax tree, every node points to its parent and
its neighbours, so a finished document is one big reference cycle that only the collector can free. Jigsaw shows it
nicely. With its default php-markdown parser, which doesn't build a tree, the collector ran 8 times for 10,000 posts.
With Jigsaw switched to CommonMark, it ran 264 times.

Taking the tree apart once the HTML is rendered lets PHP free it straight away, and that halved the collector's work:
{{TREE_SENTENCE}} Most of the garbage that's left comes from the converter Hyde builds for every page, because an
environment and its extensions point at each other too. Reusing the converter would take care of that, but it needs
some care around per-page state, so that one's a design job rather than a patch.

## Things I didn't expect

**The JIT makes most builds slower.** PHP's JIT has to compile hot code before it pays off, and a static site build
doesn't run long enough for that to happen.

{{JIT_TABLE}}

OPcache doesn't help either. On the command line, the cache only lives for one process, so it compiles every file once
per build, which is what PHP does without it anyway.

**This blog post crashed the build.** The first time I built the site with this post in it, Hyde refused:
`DateString::__construct(): Argument #1 ($string) must be of type string, int given`. My front matter said
`date: 2026-10-06`, without quotes. YAML reads an unquoted date as a date, the parser hands Hyde a Unix timestamp, and
Hyde only expects a string. Quoting it works around it, and the fix in Hyde is two lines. It's also exactly how most
people would write a date, which is why it's worth fixing rather than documenting.

**Post length matters less than you'd think.** {{CONTENT_SENTENCE}}

**The build time Hyde prints isn't the time you wait.** {{TIMER_SENTENCE}} It's worth timing builds from outside
the process.

## What I'm taking away from this

**Benchmark the shape, not the speed.** At 100 posts, the two bugs together cost about a quarter of a second, and the
build still worked out to a few milliseconds per page. Every number I'd ever seen was true. What I'd never seen was
how the number *changes* as the site grows, and that's where the problem was. A benchmark at 10 and 100 times your
real size is cheap to run, and it's the only way to see a curve.

**Most people never hit this, and that's exactly why it survived.** I presume it hasn't been a real problem for
anyone, because very few Hyde sites have thousands of pages. Small sites are fast either way, so there
was never a symptom to investigate. That isn't a reason not to fix it. Sites grow, and someone migrating a big
WordPress blog shouldn't find out the hard way.

**Measure before you guess, and measure the right thing.** Both bugs were in places I'd never have looked, a link
rewriter and an `if` statement in the page `<head>`, and the profiler found each of them in the first minute. My one
real guess, the garbage collector, I first ruled out with a test that couldn't actually answer the question. The
collector did turn out to be the last part of the curve. `gc_status()` answered that in one build.

**Prove a fix is a fix.** A faster function that changes the output is a regression with good marketing. The
differential test, and Hyde's own tests passing unchanged, are what make me comfortable shipping these.

**Measure from the outside.** The time a tool prints about itself and the time you spend waiting are not the same
number, and the gap grows with the site.

So, is the sentence from the other post true? With the fixes, it is: Hyde builds a post in about 6 ms with a minimal
layout and 10–13 ms with the full default theme, and the cost per page stays roughly flat as the site grows,
{{LARGE_SENTENCE}}. Without them, it isn't. Jigsaw is faster, mostly because of its Markdown parser, and Hugo is faster
than everyone. If raw build speed for a huge site is what you need most, Hugo is the honest answer. For everything
else, the difference between a 5-second build and a 1-second build is mostly how long you look at your terminal.

I'm glad I went looking anyway.

## Run it yourself

Everything is in the [benchmark repository](https://github.com/emmadesilva/hydephp-benchmarks): the harness, the
templates for each generator, the patches with their differential test, and every raw number behind this post.

```bash
git clone https://github.com/emmadesilva/hydephp-benchmarks
cd hydephp-benchmarks
benchmarks/setup.sh
php benchmarks/bench compare --posts=1000
php benchmarks/report
```

If you get very different numbers, or think one of the generators is set up unfairly, open an issue. I'd much rather
fix the benchmark than defend it.
