<?php

declare(strict_types=1);

namespace Bench;

/**
 * Generates a deterministic corpus of blog posts.
 *
 * Every generator receives byte-identical Markdown bodies and front matter values,
 * so the only thing that differs between runs is the tool doing the work.
 * The same seed always produces the same corpus, on any machine.
 */
final class Corpus
{
    private const WORDS = [
        'static', 'site', 'build', 'page', 'content', 'markdown', 'layout', 'template', 'deploy', 'server',
        'cache', 'render', 'output', 'config', 'theme', 'route', 'asset', 'image', 'feed', 'index',
        'the', 'a', 'and', 'of', 'to', 'in', 'is', 'it', 'that', 'for', 'with', 'as', 'on', 'this', 'we',
        'you', 'are', 'be', 'can', 'not', 'from', 'or', 'by', 'an', 'they', 'which', 'when', 'all', 'there',
        'would', 'their', 'what', 'about', 'if', 'out', 'up', 'so', 'into', 'just', 'more', 'some', 'time',
        'people', 'year', 'way', 'day', 'thing', 'work', 'part', 'place', 'case', 'point', 'fact', 'week',
        'number', 'group', 'problem', 'system', 'program', 'question', 'team', 'idea', 'reason', 'result',
        'change', 'morning', 'evening', 'history', 'process', 'library', 'package', 'release', 'version',
        'developer', 'browser', 'request', 'response', 'function', 'variable', 'class', 'method', 'string',
        'quickly', 'slowly', 'really', 'always', 'never', 'often', 'probably', 'actually', 'simply', 'mostly',
        'good', 'new', 'first', 'last', 'long', 'great', 'little', 'own', 'other', 'old', 'right', 'big',
        'high', 'different', 'small', 'large', 'next', 'early', 'young', 'important', 'few', 'public', 'bad',
        'make', 'know', 'take', 'see', 'come', 'think', 'look', 'want', 'give', 'use', 'find', 'tell', 'ask',
        'seem', 'feel', 'try', 'leave', 'call', 'write', 'read', 'ship', 'test', 'measure', 'compare', 'run',
    ];

    private const CATEGORIES = ['engineering', 'releases', 'tutorials', 'community', 'performance', 'design'];
    private const AUTHORS = ['Emma', 'Alex', 'Sam', 'Robin', 'Kim', 'Charlie'];

    public function __construct(private readonly int $seed = 2026)
    {
    }

    /**
     * @return \Generator<int, Post>
     */
    public function posts(int $count, string $size = 'medium'): \Generator
    {
        mt_srand($this->seed, MT_RAND_MT19937);

        [$minSections, $maxSections] = match ($size) {
            'short' => [1, 2],
            'medium' => [3, 6],
            'long' => [10, 16],
        };

        $start = strtotime('2015-01-01 09:00:00 UTC');
        $previous = [];

        for ($i = 1; $i <= $count; $i++) {
            $title = $this->title();
            $slug = sprintf('post-%05d-%s', $i, $this->slugify($title));
            // Spread posts out roughly a few hours apart so dates are unique and sortable.
            $timestamp = $start + $i * 3 * 3600 + mt_rand(0, 3599);

            $body = $this->body(mt_rand($minSections, $maxSections));

            // About a third of posts link back to an earlier one, like real blogs do. Every generator
            // writes posts to posts/<slug>.html, so a plain relative link works in all of them.
            if ($previous !== [] && mt_rand(1, 3) === 1) {
                [$linkSlug, $linkTitle] = $previous[mt_rand(0, count($previous) - 1)];
                $body .= "\nThis builds on [$linkTitle]($linkSlug.html), if you want the background.\n";
            }

            yield new Post(
                slug: $slug,
                title: $title,
                description: ucfirst($this->sentence(10, 18)),
                author: self::AUTHORS[mt_rand(0, count(self::AUTHORS) - 1)],
                category: self::CATEGORIES[mt_rand(0, count(self::CATEGORIES) - 1)],
                date: gmdate('Y-m-d\TH:i:s\Z', $timestamp),
                body: $body,
            );

            $previous[] = [$slug, $title];
            if (count($previous) > 50) {
                array_shift($previous);
            }
        }
    }

    private function body(int $sections): string
    {
        $blocks = [$this->paragraph()];

        for ($s = 0; $s < $sections; $s++) {
            $blocks[] = '## '.ucfirst($this->words(mt_rand(2, 6)));
            $blocks[] = $this->paragraph();

            // Sprinkle in the elements real posts tend to have.
            $roll = mt_rand(1, 100);
            if ($roll <= 30) {
                $blocks[] = $this->list(ordered: mt_rand(0, 1) === 1);
            } elseif ($roll <= 50) {
                $blocks[] = $this->codeBlock();
            } elseif ($roll <= 60) {
                $blocks[] = '> '.ucfirst($this->sentence(12, 30)).'.';
            } elseif ($roll <= 68) {
                $blocks[] = $this->table();
            } elseif ($roll <= 75) {
                $blocks[] = '### '.ucfirst($this->words(mt_rand(2, 5)));
            }

            $blocks[] = $this->paragraph();
        }

        return implode("\n\n", $blocks)."\n";
    }

    private function paragraph(): string
    {
        $sentences = [];
        for ($i = mt_rand(3, 7); $i > 0; $i--) {
            $sentences[] = ucfirst($this->inlineSentence()).'.';
        }

        return implode(' ', $sentences);
    }

    private function inlineSentence(): string
    {
        $words = explode(' ', $this->sentence(8, 22));
        $roll = mt_rand(1, 100);
        $at = mt_rand(1, count($words) - 1);

        // Inline formatting and links, so the Markdown parser has real work to do.
        if ($roll <= 12) {
            $words[$at] = '**'.$words[$at].'**';
        } elseif ($roll <= 22) {
            $words[$at] = '*'.$words[$at].'*';
        } elseif ($roll <= 32) {
            $words[$at] = '`'.$words[$at].'()`';
        } elseif ($roll <= 42) {
            $words[$at] = '['.$words[$at].'](https://example.com/'.$words[$at].')';
        }

        return implode(' ', $words);
    }

    private function list(bool $ordered): string
    {
        $items = [];
        for ($i = 1, $n = mt_rand(3, 6); $i <= $n; $i++) {
            $items[] = ($ordered ? "$i. " : '- ').ucfirst($this->sentence(4, 12));
        }

        return implode("\n", $items);
    }

    private function codeBlock(): string
    {
        $lines = ['```php', '<?php', ''];
        for ($i = mt_rand(3, 10); $i > 0; $i--) {
            $lines[] = sprintf('$%s = %s(\'%s\');', $this->word(), $this->word(), $this->words(mt_rand(1, 3)));
        }
        $lines[] = '```';

        return implode("\n", $lines);
    }

    private function table(): string
    {
        $rows = ['| Name | Value | Notes |', '| --- | --- | --- |'];
        for ($i = mt_rand(2, 5); $i > 0; $i--) {
            $rows[] = sprintf('| %s | %d | %s |', ucfirst($this->word()), mt_rand(1, 999), $this->words(mt_rand(2, 5)));
        }

        return implode("\n", $rows);
    }

    private function title(): string
    {
        return ucfirst($this->words(mt_rand(3, 7)));
    }

    private function sentence(int $min, int $max): string
    {
        return $this->words(mt_rand($min, $max));
    }

    private function words(int $count): string
    {
        $words = [];
        for ($i = 0; $i < $count; $i++) {
            $words[] = $this->word();
        }

        return implode(' ', $words);
    }

    private function word(): string
    {
        return self::WORDS[mt_rand(0, count(self::WORDS) - 1)];
    }

    private function slugify(string $title): string
    {
        return implode('-', array_slice(explode(' ', strtolower($title)), 0, 4));
    }
}

final class Post
{
    public function __construct(
        public readonly string $slug,
        public readonly string $title,
        public readonly string $description,
        public readonly string $author,
        public readonly string $category,
        public readonly string $date,
        public readonly string $body,
    ) {
    }

    /** The date part only, for generators (like Jekyll) that want it in the filename. */
    public function day(): string
    {
        return substr($this->date, 0, 10);
    }

    /** Front matter as YAML. Every value is quoted, so no generator has to guess at types. */
    public function frontMatter(array $extra = []): string
    {
        $fields = [
            'title' => $this->title,
            'description' => $this->description,
            'author' => $this->author,
            'category' => $this->category,
            'date' => $this->date,
        ] + $extra;

        $yaml = "---\n";
        foreach ($fields as $key => $value) {
            $yaml .= $key.': '.(is_string($value) ? '"'.addcslashes($value, '"\\').'"' : $value)."\n";
        }

        return $yaml."---\n\n";
    }

    public function markdown(array $extra = []): string
    {
        return $this->frontMatter($extra).$this->body;
    }
}
