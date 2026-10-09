<?php

declare(strict_types=1);

namespace Hyde\Markdown\Processing;

use Hyde\Hyde;
use Illuminate\Support\Str;
use Hyde\Support\Filesystem\MediaFile;
use Hyde\Foundation\Kernel\RouteCollection;
use Hyde\Markdown\Contracts\MarkdownPostProcessorContract;

/**
 * Proposed fix for the build time growing with the square of the page count. Drop-in replacement for
 * vendor/hyde/framework/src/Markdown/Processing/DynamicMarkdownLinkProcessor.php (hyde/framework 2.0.3).
 *
 * The original loops over every route on the site for every page it compiles, resolving each route's
 * link and running str_replace() over the page's HTML whether or not the page links there. On a blog
 * with N posts that is N * N link resolutions.
 *
 * This version finds the links that are actually in the HTML with one regex, and looks each one up in
 * a source path map that is built once per route collection instead of once per page.
 */
class DynamicMarkdownLinkProcessor implements MarkdownPostProcessorContract
{
    /** @var array<string, \Hyde\Support\Filesystem\MediaFile>|null */
    protected static ?array $assetMapCache = null;

    /** @var \WeakMap<\Hyde\Foundation\Kernel\RouteCollection, array{int, array<string, \Hyde\Support\Models\Route>}>|null */
    protected static ?\WeakMap $routeMapCache = null;

    public static function postprocess(string $html): string
    {
        // Cheap bail-out for the common case of a page with no links or images at all.
        if (! str_contains($html, '<a href="') && ! str_contains($html, '<img src="')) {
            return $html;
        }

        return preg_replace_callback('/<(a href|img src)="\/?([^"]*)"/', function (array $matches): string {
            [$tag, $attribute, $path] = $matches;

            if ($attribute === 'a href') {
                $route = static::routeMap()[$path] ?? null;

                return $route ? sprintf('<a href="%s"', $route->getLink()) : $tag;
            }

            $mediaFile = static::assetMap()[$path] ?? null;

            return $mediaFile ? sprintf('<img src="%s"', static::assetPath($mediaFile)) : $tag;
        }, $html) ?? $html;
    }

    /** @return array<string, \Hyde\Support\Models\Route> */
    protected static function routeMap(): array
    {
        $routes = Hyde::routes();
        static::$routeMapCache ??= new \WeakMap();

        // Routes can still be added after the map is built (extensions, tests), so rebuild when the count changes.
        [$count, $map] = static::$routeMapCache[$routes] ?? [-1, []];

        if ($count !== $routes->count()) {
            $map = static::buildRouteMap($routes);
            static::$routeMapCache[$routes] = [$routes->count(), $map];
        }

        return $map;
    }

    /** @return array<string, \Hyde\Support\Models\Route> */
    protected static function buildRouteMap(RouteCollection $routes): array
    {
        $map = [];

        /** @var \Hyde\Support\Models\Route $route */
        foreach ($routes as $route) {
            $map[$route->getSourcePath()] = $route;
        }

        return $map;
    }

    /** @return array<string, \Hyde\Support\Filesystem\MediaFile> */
    protected static function assetMap(): array
    {
        if (static::$assetMapCache === null) {
            static::$assetMapCache = [];

            foreach (MediaFile::all() as $mediaFile) {
                static::$assetMapCache[$mediaFile->getPath()] = $mediaFile;
            }
        }

        return static::$assetMapCache;
    }

    protected static function assetPath(MediaFile $mediaFile): string
    {
        return Hyde::asset(Str::after($mediaFile->getPath(), '_media/'))->getLink();
    }

    /** @internal Testing helper to reset the asset map cache. */
    public static function resetAssetMapCache(): void
    {
        static::$assetMapCache = null;
    }
}
