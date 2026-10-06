<?php

/*
 * Differential test for the patches in this directory.
 *
 *   cd benchmarks/work/compare/hyde-minimal-1000-medium   # any prepared Hyde workspace
 *   php ../../../patches/verify.php
 *
 * Boots the Hyde project in the current directory, then feeds the original and the patched
 * DynamicMarkdownLinkProcessor the same HTML from the point of view of every page on the site,
 * and fails on the first difference. The inputs are the cases from Hyde's own
 * DynamicMarkdownLinkProcessorTest, plus a link to every route and every media file, with and
 * without a leading slash. Then it checks the one condition the Features::hasRss() patch changes
 * gives the same answer as before, with and without posts on the site.
 */

declare(strict_types=1);

use Hyde\Hyde;
use Hyde\Support\Facades\Render;
use Hyde\Support\Filesystem\MediaFile;
use Hyde\Markdown\Processing\DynamicMarkdownLinkProcessor as Original;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/app/bootstrap.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Load the patched processor under another name so both versions can live side by side.
$load = function (string $file, string $class) {
    $patch = file_get_contents(__DIR__."/$file");
    eval('?>'.str_replace(['declare(strict_types=1);', "class $class "], ['', "class Patched$class "], $patch));
};
$load('DynamicMarkdownLinkProcessor.php', 'DynamicMarkdownLinkProcessor');
$patched = \Hyde\Markdown\Processing\PatchedDynamicMarkdownLinkProcessor::class;

$inputs = [
    '<p><a href="_pages/index.blade.php">Home</a></p>',
    '<p><a href="/_pages/index.blade.php">Home</a></p>',
    '<p><img src="_media/app.css" alt="Logo" /></p>',
    '<p><img src="/_media/app.css" alt="Logo" /></p>',
    "<a href=\"_pages/index.blade.php\">Home</a>\n<img src=\"_media/app.css\" alt=\"Logo\" />",
    '<p>This is a regular <a href="https://example.com">link</a> with no Hyde syntax.</p>',
    '<p><a href="_pages/non-existent.blade.php">Non-existent Route</a></p>',
    '<p><img src="_media/non-existent.png" alt="Non-existent Asset" /></p>',
    '<p><a href="post-00001.html">A plain relative link</a> and <a href="#top">an anchor</a></p>',
    '<p>No links at all</p>',
    '',
];

$everything = '';
foreach (Hyde::routes() as $route) {
    $everything .= sprintf('<a href="%1$s">x</a> <a href="/%1$s" class="y">z</a>'."\n", $route->getSourcePath());
}
foreach (MediaFile::all() as $file) {
    $everything .= sprintf('<img src="%1$s" /> <img src="/%1$s" alt="" />'."\n", $file->getPath());
}
$inputs[] = $everything;

$checked = 0;
foreach (Hyde::pages() as $page) {
    Render::setPage($page);

    foreach ([...$inputs, $page->compile()] as $html) {
        $expected = Original::postprocess($html);
        $actual = $patched::postprocess($html);

        if ($expected !== $actual) {
            fwrite(STDERR, "Mismatch on {$page->getSourcePath()}\n--- original\n$expected\n--- patched\n$actual\n");
            exit(1);
        }
        $checked++;
    }
}

printf("DynamicMarkdownLinkProcessor OK: %s comparisons across %d pages, outputs identical.\n", number_format($checked), Hyde::pages()->count());

// Features::hasRss(). The patch changes only its last condition, so compare that condition
// before and after, with posts on the site and without.
$allFiles = Hyde::files()->all();
foreach ([true, false] as $withPosts) {
    if (! $withPosts) {
        Hyde::files()->forget(array_keys(array_filter($allFiles, fn ($file) => $file->pageClass === \Hyde\Pages\MarkdownPost::class)));
    }

    $original = count(\Hyde\Pages\MarkdownPost::files()) > 0;
    $fixed = Hyde::files()->contains(fn (\Hyde\Support\Filesystem\SourceFile $file): bool => $file->pageClass === \Hyde\Pages\MarkdownPost::class);

    if ($original !== $fixed || $original !== $withPosts) {
        fwrite(STDERR, 'hasRss() post check mismatch '.($withPosts ? 'with' : 'without')." posts\n");
        exit(1);
    }
}
echo "Features::hasRss() OK: the patched condition agrees with and without posts.\n";
