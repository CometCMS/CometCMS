<?php

declare(strict_types=1);

namespace CometCMS\Site;

use CometCMS\Core\Security;

/** Navigation and contextual actions for authenticated, full-page previews. */
final class PreviewAdminBar
{
    public function __construct(private readonly SiteContext $ctx) {}

    public function render(array $state, bool $unsaved = false): string
    {
        $adminTheme = strtolower((string) ($this->ctx->adminUser['theme'] ?? 'blue'));
        if (!in_array($adminTheme, ['blue', 'green', 'purple', 'orange', 'red', 'cyan', 'dark'], true)) {
            $adminTheme = 'blue';
        }
        $de = str_starts_with((string) ($this->ctx->adminUser['language'] ?? 'en'), 'de');
        $text = $de ? [
            'backend' => 'Zurück zur Verwaltung', 'adminBar' => 'Website-Verwaltung', 'preview' => 'Vorschau', 'notice' => 'Du verwaltest diese Website. Die Vorschau zeigt gespeicherte Inhalte, einschließlich Entwürfen.',
            'edit' => 'Seite bearbeiten', 'create' => 'Neu erstellen', 'entries' => 'Einträge', 'editEntry' => 'Eintrag bearbeiten',
            'profile' => 'Mein Profil', 'language' => 'Vorschausprache',
            'draft' => 'Entwurf', 'protected' => 'Geschützt', 'published' => 'Veröffentlicht', 'scheduled' => 'Geplant', 'archived' => 'Archiviert',
        ] : [
            'backend' => 'Back to admin', 'adminBar' => 'Site administration', 'preview' => 'Preview', 'notice' => 'You are administering this website. Preview shows saved content, including drafts.',
            'edit' => 'Edit page', 'create' => 'Create new', 'entries' => 'Entries', 'editEntry' => 'Edit entry',
            'profile' => 'My profile', 'language' => 'Preview language',
            'draft' => 'Draft', 'protected' => 'Protected', 'published' => 'Published', 'scheduled' => 'Scheduled', 'archived' => 'Archived',
        ];
        if ($unsaved) {
            $text['notice'] = $de ? 'Diese Vorschau enthält ungespeicherte Änderungen. Die Website bleibt unverändert.' : 'This preview includes unsaved changes. The website remains unchanged.';
        }
        $type = $state['type'] ?? null;
        $entry = $state['entry'] ?? null;
        $name = (string) ($type['name'] ?? '');
        $savedEntry = $entry !== null ? $this->ctx->content->findByIdentifier($name, (string) ($entry['uid'] ?? $entry['id'])) : null;
        $builderUrl = null;
        $variant = $entry !== null ? 'detail' : 'index';
        if ($type !== null && $this->ctx->isPublicType($name)
            && in_array($variant, $this->ctx->site->enabledVariantsFor($type), true)
            && $this->ctx->adminAllows('site.manage') && ($entry === null || $savedEntry !== null)) {
            $entryId = $savedEntry !== null ? (string) ($savedEntry['uid'] ?? $savedEntry['id']) : '';
            $builderUrl = $this->ctx->adminUrl('/page-builder/builder/layout/' . rawurlencode($name) . '/' . $variant,
                $entryId !== '' ? ['entry' => $entryId] : []);
            if ($savedEntry !== null) {
                foreach ($type['fields'] ?? [] as $field => $config) {
                    if (($config['type'] ?? '') === 'page' && $this->ctx->adminAllows('content.update', $name, $savedEntry, [$field])) {
                        $builderUrl = $this->ctx->adminUrl('/page-builder/builder/page/' . rawurlencode($name) . '/' . rawurlencode($entryId), ['field' => $field]);
                        break;
                    }
                }
            }
        }
        $backUrl = $this->ctx->previewReturnUrl();
        $backLabel = $text['backend'];
        // Labels may collapse to icons on narrow screens; the title keeps them discoverable.
        $link = fn(string $url, string $label, string $icon, string $class = ''): string => '<a class="c-admin-action ' . $class . '" href="' . Security::e($url) . '" title="' . Security::e($label) . '">'
            . $this->icon($icon) . '<span class="c-admin-label">' . Security::e($label) . '</span></a>';
        $actions = '';
        if ($name !== '') {
            $collection = '/content/' . rawurlencode($name);
            $entryUrl = null;
            if ($savedEntry !== null && $this->ctx->adminAllows('content.update', $name, $savedEntry)) {
                $entryUrl = $this->ctx->adminUrl($collection . '/' . rawurlencode((string) ($savedEntry['uid'] ?? $savedEntry['id'])));
            }
            if ($builderUrl !== null) {
                $actions .= $link($builderUrl, $text['edit'], 'layout', 'c-admin-action-primary');
            } elseif ($entryUrl !== null) {
                $actions .= $link($entryUrl, $text['editEntry'], 'edit', 'c-admin-action-primary');
            }
            if ($this->ctx->adminAllows('content.read', $name)) {
                $actions .= $link($this->ctx->adminUrl($collection), $text['entries'], 'list');
            }
            if ($this->ctx->adminAllows('content.create', $name)) {
                $actions .= $link($this->ctx->adminUrl($collection . '/new'), $text['create'], 'plus');
            }
            if ($builderUrl !== null && $entryUrl !== null) {
                $actions .= $link($entryUrl, $text['editEntry'], 'edit');
            }
        }
        $status = $unsaved ? 'preview' : ($entry['status'] ?? 'preview');
        if ($entry !== null && $status === 'published' && !$this->ctx->content->isPubliclyVisible($entry, $this->ctx->locale ?: $this->ctx->defaultLocale())) {
            $status = 'scheduled';
        }
        $workspace = $this->ctx->site->workspaceLabel();
        $title = (string) ($entry['title'] ?? $type['label'] ?? $this->ctx->siteTitle());
        $user = $this->ctx->adminUser;
        $username = trim((string) (($user['display_name'] ?? '') ?: ($user['username'] ?? '')));
        $initials = '';
        foreach (array_slice(preg_split('/\s+/u', $username) ?: [], 0, 2) as $part) {
            preg_match('/./us', $part, $first);
            $initials .= strtoupper($first[0] ?? '');
        }
        // A 2x bar-sized copy: the full logo would add ~120 KB to every preview page.
        $logoFile = in_array($adminTheme, ['blue', 'dark'], true) ? 'cometcms-white-bar.png' : 'cometcms-' . $adminTheme . '-bar.png';
        [$logoWidth, $logoHeight] = getimagesize(__DIR__ . '/assets/' . $logoFile);
        $logo = base64_encode((string) file_get_contents(__DIR__ . '/assets/' . $logoFile));
        $statusClass = in_array($status, ['published', 'protected', 'draft', 'preview', 'scheduled', 'archived'], true) ? $status : 'preview';
        $statusHtml = '<span class="c-admin-status c-admin-status-' . $statusClass . '"'
            . ($statusClass === 'preview' ? ' title="' . Security::e($text['notice']) . '"' : '')
            . '><span class="c-admin-status-dot" aria-hidden="true"></span>'
            . Security::e($text[$statusClass]) . '</span>';

        return '<nav class="c-preview-bar" data-admin-theme="' . $adminTheme . '" aria-label="' . Security::e($text['adminBar']) . '"><div class="c-admin-shell">'
            . '<a class="c-admin-brand" href="' . Security::e($backUrl) . '" aria-label="CometCMS · ' . Security::e($backLabel) . '" title="' . Security::e($backLabel) . '">'
            . $this->icon('back') . '<img src="data:image/png;base64,' . $logo . '" alt="CometCMS" width="' . $logoWidth . '" height="' . $logoHeight . '"></a>'
            . '<div class="c-admin-context" title="' . Security::e($workspace . ' · ' . $title) . '">'
            . '<span class="c-admin-workspace">' . Security::e($workspace) . '</span><span class="c-admin-title">' . Security::e($title) . '</span></div>'
            . '<div class="c-admin-meta">' . $statusHtml . $this->languages($type, $entry, $text['language']) . $this->templateMenu($state, $savedEntry, $de) . '</div>'
            . '<div class="c-preview-actions">' . $actions . '</div>'
            . '<details class="c-admin-account c-admin-dropdown"><summary aria-label="' . Security::e($username) . '" title="' . Security::e($username) . '"><span class="c-admin-avatar" aria-hidden="true">' . Security::e($initials) . '</span>'
            . '<span class="c-admin-username">' . Security::e($username) . '</span>' . $this->icon('chevron') . '</summary>'
            . '<div class="c-admin-dropdown-panel">' . $link($this->ctx->adminUrl('/profile'), $text['profile'], 'user')
            . $link($this->ctx->adminUrl('/dashboard'), $text['backend'], 'back') . '</div></details>'
            . '</div></nav>';
    }

    private function languages(?array $type, ?array $entry, string $label): string
    {
        $locales = $this->ctx->locales();
        if ($type !== null && !empty($type['locales'])) {
            $locales = array_values(array_intersect($locales, $type['locales']));
        }
        $current = strtoupper($this->ctx->locale ?: $this->ctx->defaultLocale());
        $value = $this->icon('globe') . '<span>' . Security::e($current) . '</span>';
        if (count($locales) < 2) {
            return '<span class="c-admin-language" title="' . Security::e($label) . '">' . $value . '</span>';
        }
        $links = '';
        foreach ($locales as $locale) {
            if ($entry !== null && $type !== null) {
                // Entry previews also support drafts without a live route.
                $url = $this->ctx->adminUrl('/site-preview/' . rawurlencode($type['name']) . '/' . rawurlencode((string) ($entry['uid'] ?? $entry['id'])), [
                    'locale' => $locale,
                    ...$this->ctx->previewNavigationQuery(),
                ]);
            } else {
                $url = $type !== null ? $this->ctx->indexUrl($type['name'], $locale) : null;
                $url ??= $this->ctx->homeUrl($locale);
            }
            $active = $locale === ($this->ctx->locale ?: $this->ctx->defaultLocale());
            $links .= '<a class="c-admin-action" href="' . Security::e($url) . '" lang="' . Security::e($locale) . '"'
                . ($active ? ' aria-current="true"' : '') . '><span>' . Security::e(strtoupper($locale)) . '</span>' . ($active ? $this->icon('check') : '') . '</a>';
        }
        return '<details class="c-admin-dropdown c-admin-languages"><summary class="c-admin-language" aria-label="' . Security::e($label) . '">'
            . $value . $this->icon('chevron') . '</summary><div class="c-admin-dropdown-panel">' . $links . '</div></details>';
    }

    /**
     * Which template assembled this page, why, and its parts with links to
     * edit each of them; for those who may design the website, once the
     * page kind has more than one template.
     */
    private function templateMenu(array $state, ?array $savedEntry, bool $de): string
    {
        $shown = $state['template'] ?? null;
        if ($shown === null || !$this->ctx->adminAllows('site.manage')) {
            return '';
        }
        // Only once the page kind uses more than its default template.
        if (count($this->ctx->templates((string) $shown['type'], (string) $shown['variant'])) < 2) {
            return '';
        }
        $text = $de ? [
            'template' => 'Layout', 'default' => 'Standardlayout', 'header' => 'Header', 'content' => 'Inhalt', 'footer' => 'Footer',
            'siteHeader' => 'Website-Header', 'siteFooter' => 'Website-Footer', 'noHeader' => 'Kein Header', 'noFooter' => 'Kein Footer',
            'editTemplate' => 'Layout bearbeiten',
            'reason.picked' => 'Für diesen Eintrag gewählt', 'reason.conditions' => 'Bedingungen erfüllt', 'reason.first' => 'Erstes Layout ohne Bedingungen',
            'reason.default' => 'Kein anderes Layout passt',
        ] : [
            'template' => 'Layout', 'default' => 'Default layout', 'header' => 'Header', 'content' => 'Content', 'footer' => 'Footer',
            'siteHeader' => 'Site header', 'siteFooter' => 'Site footer', 'noHeader' => 'No header', 'noFooter' => 'No footer',
            'editTemplate' => 'Edit layout',
            'reason.picked' => 'Chosen for this entry', 'reason.conditions' => 'Its conditions match', 'reason.first' => 'First layout without conditions',
            'reason.default' => 'No other layout applies',
        ];
        $template = $shown['template'];
        $type = (string) $shown['type'];
        $variant = (string) $shown['variant'];
        $name = $template['id'] === SiteRepository::DEFAULT_TEMPLATE ? $text['default'] : (string) $template['name'];
        $reason = $text['reason.' . $shown['reason']] ?? '';
        if ($shown['reason'] === 'conditions') {
            $fields = $this->ctx->site->types()[$type]['fields'] ?? [];
            $reason .= ': ' . implode(', ', array_map(static function (array $condition) use ($fields, $state, $de): string {
                if ($condition['field'] === 'uid') {
                    return ($de ? 'Eintrag' : 'Entry') . ' = ' . (string) ($state['entry']['title'] ?? $state['entry']['uid'] ?? '');
                }
                return (is_string($fields[$condition['field']]['label'] ?? null) && $fields[$condition['field']]['label'] !== '' ? $fields[$condition['field']]['label'] : $condition['field']) . ' ' . strtr($condition['op'], ['equals' => '=', 'not_equals' => '≠', 'contains' => '∋', 'not_empty' => '≠ ∅', 'empty' => '= ∅'])
                    . ($condition['value'] !== null ? ' ' . (is_bool($condition['value']) ? ($condition['value'] ? 'true' : 'false') : (string) $condition['value']) : '');
            }, $template['conditions']));
        }

        $row = function (string $label, string $value, ?string $url): string {
            $body = '<span class="c-admin-part"><small>' . Security::e($label) . '</small><span>' . Security::e($value) . '</span></span>';
            return $url !== null
                ? '<a class="c-admin-action" href="' . Security::e($url) . '">' . $body . $this->icon('edit') . '</a>'
                : '<span class="c-admin-action c-admin-action-static">' . $body . '</span>';
        };
        $rows = '';
        foreach (['header', 'content', 'footer'] as $part) {
            if ($part === 'content') {
                $entryId = $savedEntry !== null ? (string) ($savedEntry['uid'] ?? $savedEntry['id']) : '';
                $rows .= $row($text['content'], $name, $this->ctx->adminUrl('/page-builder/builder/layout/' . rawurlencode($type) . '/' . $variant,
                    array_filter(['template' => $template['id'], 'entry' => $entryId], static fn(string $item): bool => $item !== '')));
                continue;
            }
            $value = (string) $template[$part];
            $component = !in_array($value, [LayoutSchema::FRAME_DEFAULT, LayoutSchema::FRAME_NONE], true) ? $this->ctx->component($value) : null;
            [$label, $url] = match (true) {
                $value === LayoutSchema::FRAME_NONE => [$text[$part === 'header' ? 'noHeader' : 'noFooter'], null],
                $component !== null && SiteRepository::fitsFrame($component) => [(string) $component['name'], $this->ctx->adminUrl('/page-builder/builder/component/' . rawurlencode($value))],
                default => [$text[$part === 'header' ? 'siteHeader' : 'siteFooter'], $this->ctx->adminUrl('/page-builder/builder/region/' . $part)],
            };
            $rows .= $row($text[$part], $label, $url);
        }
        $edit = '<a class="c-admin-action c-admin-action-separated" href="' . Security::e($this->ctx->adminUrl('/page-builder/templates', ['type' => $type, 'variant' => $variant, 'template' => $template['id']])) . '">'
            . $this->icon('layout') . '<span>' . Security::e($text['editTemplate']) . '</span></a>';

        return '<details class="c-admin-dropdown c-admin-templates"><summary class="c-admin-language" title="' . Security::e($text['template'] . ': ' . $name) . '">'
            . $this->icon('layout') . '<span class="c-admin-template-name">' . Security::e($name) . '</span>' . $this->icon('chevron') . '</summary>'
            . '<div class="c-admin-dropdown-panel"><p class="c-admin-template-reason"><strong>' . Security::e($text['template'] . ': ' . $name) . '</strong><span>' . Security::e($reason) . '</span></p>'
            . $rows . $edit . '</div></details>';
    }

    private function icon(string $name): string
    {
        $paths = [
            'back' => '<path d="m9 5-7 7 7 7M2 12h19"/>',
            'edit' => '<path d="m16 3 5 5M4 15 16 3a2 2 0 0 1 5 5L9 20l-6 1Z"/>',
            'list' => '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
            'plus' => '<path d="M12 4v16M4 12h16"/>',
            'layout' => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 9v12"/>',
            'globe' => '<circle cx="12" cy="12" r="9"/><ellipse cx="12" cy="12" rx="4" ry="9"/><path d="M3 12h18"/>',
            'chevron' => '<path d="m6 9 6 6 6-6"/>',
            'check' => '<path d="m5 12 4 4L19 6"/>',
            'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2"/>',
        ];
        return '<svg class="c-admin-icon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . ($paths[$name] ?? '') . '</svg>';
    }
}
