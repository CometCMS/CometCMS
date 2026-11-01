<?php

declare(strict_types=1);

namespace CometCMS\Site;

/**
 * Search of the website's pages for the search element.
 *
 * Only entries a visitor could open are found: published entries of public
 * content types that have website pages. Hits carry nothing but a title, a
 * URL and the type's label, so a search reveals no more than the pages do.
 * The bundled script is allowed by its exact CSP hash, like Tabs.
 */
final class SiteSearch
{
    /** Hits shown at most in the search element's dropdown. */
    public const MAX_RESULTS = 7;

    /** Shorter queries match too much of a large site to be worth scanning for. */
    public const MIN_QUERY_LENGTH = 2;

    public const MAX_QUERY_LENGTH = 100;

    /** Hits per page of a collection on the search results page, at most. */
    public const MAX_PAGE_SIZE = 100;

    /**
     * Field types whose text can be searched; select fields match their keys
     * and labels, page content the texts written into it.
     */
    public const FIELD_TYPES = ['text', 'slug', 'textarea', 'markdown', 'html', 'select', 'page'];

    public function __construct(private readonly SiteContext $ctx) {}

    public static function script(): string
    {
        return (string) file_get_contents(__DIR__ . '/assets/search.js') . "\ninitSearch(document);";
    }

    public static function cspSource(): string
    {
        return "'sha256-" . base64_encode(hash('sha256', self::script(), true)) . "'";
    }

    /**
     * The types a search looks through and the fields searched in each.
     * Empty $collections means every type with pages; empty $fields means the
     * title and every searchable field. Types left without fields drop out.
     *
     * @return array<string, array<string, array>> type => field => config
     */
    public function targets(array $collections = [], array $fields = []): array
    {
        $names = $collections !== [] ? $collections : array_keys($this->ctx->site->types());
        $targets = [];

        foreach ($names as $name) {
            $name = (string) $name;
            $schema = $this->ctx->type($name);
            if ($schema === null || !$this->hasPages($name)) {
                continue;
            }

            $searchable = ['title' => ['type' => 'text']];
            foreach (is_array($schema['fields'] ?? null) ? $schema['fields'] : [] as $key => $config) {
                if (is_array($config) && in_array($config['type'] ?? 'text', self::FIELD_TYPES, true)) {
                    $searchable[(string) $key] = $config;
                }
            }
            if ($fields !== []) {
                $searchable = array_intersect_key($searchable, array_flip($fields));
            }
            if ($searchable !== []) {
                $targets[$name] = $searchable;
            }
        }

        return $targets;
    }

    /**
     * Entries matching $query, best first (see matches()), for the search
     * element's dropdown.
     *
     * @return list<array{title: string, url: string, type: string}>
     */
    public function search(string $query, array $collections = [], array $fields = [], int $limit = self::MAX_RESULTS): array
    {
        $hits = [];
        foreach (array_slice($this->matches($query, $collections, $fields), 0, max(1, min(self::MAX_RESULTS, $limit))) as $match) {
            $hits[] = ['title' => (string) ($match['entry']['title'] ?? ''), 'url' => $match['url'], 'type' => $match['label']];
        }

        return $hits;
    }

    /**
     * One page of matches for a collection on the search results page;
     * entries as stored, to be localized where they are shown.
     *
     * @return array{data: list<array{type: string, entry: array}>, total: int}
     */
    public function page(string $query, array $collections, array $fields, int $limit, int $offset): array
    {
        $matches = $this->matches($query, $collections, $fields);
        $slice = array_slice($matches, max(0, $offset), max(1, min(self::MAX_PAGE_SIZE, $limit)));

        return [
            'data' => array_map(static fn(array $match): array => ['type' => $match['type'], 'entry' => $match['raw']], $slice),
            'total' => count($matches),
        ];
    }

    /**
     * The newest entries of the searched types, standing in for hits where
     * there is no query yet, e.g. while the builder designs the results page.
     *
     * @return list<array{type: string, entry: array}>
     */
    public function samples(array $collections, int $limit): array
    {
        $samples = [];
        foreach (array_keys($this->targets($collections)) as $type) {
            foreach ($this->ctx->query($type, ['sort' => '-published_at', 'limit' => $limit])['data'] as $entry) {
                $samples[] = ['type' => $type, 'entry' => $entry, 'date' => (string) ($entry['published_at'] ?? '')];
            }
        }
        usort($samples, static fn(array $a, array $b): int => $b['date'] <=> $a['date']);

        return array_map(static fn(array $sample): array => ['type' => $sample['type'], 'entry' => $sample['entry']], array_slice($samples, 0, max(1, $limit)));
    }

    /**
     * A query that finds something, for previews of the results page: of the
     * words in the newest titles, the one with the most hits; '' without entries.
     */
    public function sampleQuery(): string
    {
        $best = '';
        $most = 0;
        $candidates = [];
        foreach ($this->targets() as $type => $fields) {
            foreach ($this->ctx->query($type, [])['data'] as $raw) {
                $entry = $this->ctx->localize($type, $raw);
                if ($this->ctx->entryUrl($type, $entry) !== null) $candidates[] = ['entry' => $entry, 'fields' => $fields];
            }
        }
        $counts = [];
        foreach ($this->samples([], 10) as $sample) {
            $title = (string) ($this->ctx->localize($sample['type'], $sample['entry'])['title'] ?? '');
            preg_match_all('/[\p{L}\p{N}]{3,}/u', $title, $words);
            foreach (array_slice(array_unique($words[0]), 0, 3) as $word) {
                $query = self::lower(self::normalizeQuery($word));
                $hits = $counts[$query] ??= count(array_filter($candidates, fn(array $candidate): bool => $query !== '' && $this->rank($candidate['entry'], $candidate['fields'], $query) !== null));
                if ($hits > $most) [$best, $most] = [self::normalizeQuery($word), $hits];
            }
        }

        return $best;
    }

    /** $query as matched: trimmed, limited, lower case; '' when too short to search for. */
    public static function normalizeQuery(mixed $query): string
    {
        $query = trim(Text::limit(is_string($query) ? $query : '', self::MAX_QUERY_LENGTH));

        return mb_strlen($query) < self::MIN_QUERY_LENGTH ? '' : $query;
    }

    /**
     * Every entry matching $query, best first: titles that start with it, then
     * titles with a word that starts with it, then any other match; newer
     * entries first within each group.
     *
     * @return list<array{type: string, label: string, entry: array, raw: array, url: string}>
     */
    private function matches(string $query, array $collections, array $fields): array
    {
        $query = self::lower(self::normalizeQuery($query));
        if ($query === '') {
            return [];
        }

        $matches = [];
        foreach ($this->targets($collections, $fields) as $type => $searchable) {
            $label = (string) (($this->ctx->type($type)['label'] ?? '') ?: $type);

            foreach ($this->ctx->query($type, [])['data'] as $raw) {
                $entry = $this->ctx->localize($type, $raw);
                $rank = $this->rank($entry, $searchable, $query);
                if ($rank === null) {
                    continue;
                }
                $url = $this->ctx->entryUrl($type, $entry);
                if ($url === null) {
                    continue;
                }
                $matches[] = ['rank' => $rank, 'date' => (string) ($entry['published_at'] ?? ''), 'type' => $type, 'label' => $label, 'entry' => $entry, 'raw' => $raw, 'url' => $url];
            }
        }

        usort($matches, static fn(array $a, array $b): int => [$a['rank'], $b['date']] <=> [$b['rank'], $a['date']]);

        return $matches;
    }

    /** Whether entries of $type open as pages of their own. */
    private function hasPages(string $type): bool
    {
        return $this->ctx->route($type) !== null
            || (($this->ctx->settings()['homepage']['type'] ?? '') === $type && $this->ctx->isPublicType($type));
    }

    /** 0–2 for a title match (see search()), 3 for another field, null for none. */
    private function rank(array $entry, array $searchable, string $query): ?int
    {
        if (isset($searchable['title'])) {
            $title = self::lower((string) ($entry['title'] ?? ''));
            $position = strpos($title, $query);
            if ($position === 0) {
                return 0;
            }
            if ($position !== false) {
                return preg_match('/(^|[^\p{L}\p{N}])' . preg_quote($query, '/') . '/u', $title) === 1 ? 1 : 2;
            }
        }

        foreach ($searchable as $key => $config) {
            if ($key !== 'title' && str_contains($this->text($entry[$key] ?? null, $config), $query)) {
                return 3;
            }
        }

        return null;
    }

    /** The lower-case text of a field value as visitors read it. */
    private function text(mixed $value, array $config): string
    {
        if (($config['type'] ?? '') === 'page') {
            return self::lower(LayoutSchema::text(LayoutSchema::pageNodes($value), $this->ctx->htmlLang(), $this->ctx->defaultLocale()));
        }
        $values = is_array($value) ? $value : [$value];
        $options = is_array($config['options'] ?? null) ? $config['options'] : [];
        $parts = [];

        foreach ($values as $item) {
            if (!is_scalar($item)) {
                continue;
            }
            $parts[] = (string) $item;
            // A select stores the option's key; visitors see its label.
            if (($config['type'] ?? '') === 'select' && !array_is_list($options) && is_scalar($options[$item] ?? null)) {
                $parts[] = (string) $options[$item];
            }
        }
        $text = implode(' ', $parts);
        if (in_array($config['type'] ?? '', ['markdown', 'html'], true)) {
            $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return self::lower($text);
    }

    /** Case folding beyond ASCII needs mbstring; without it, other letters must match exactly. */
    private static function lower(string $text): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
    }
}
