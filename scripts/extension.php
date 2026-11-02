<?php

// Create, validate, preview or pack an extension without a running CMS, with the compiler checks. Site-dependent seed validation runs on installation.
//   php scripts/extension.php new <folder> [vendor/name] [--block <name>]
//   php scripts/extension.php validate <folder-or-zip> [--json]
//   php scripts/extension.php render <folder-or-zip> <block> [props.json] [--json]
//   php scripts/extension.php pack <folder> [output.zip]

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $path = dirname(__DIR__) . '/cms/app/' . str_replace('\\', '/', substr($class, strlen('CometCMS\\'))) . '.php';
    if (str_starts_with($class, 'CometCMS\\') && is_file($path)) {
        require $path;
    }
});

use CometCMS\Extensions\Extension;
use CometCMS\Extensions\ExtensionException;
use CometCMS\Extensions\ExtensionRegistry;
use CometCMS\Site\Blocks\BlockScripts;
use CometCMS\Site\Blocks\BlockTemplate;

$script = $argv[0];
$json = in_array('--json', $argv, true);
$options = [];
$arguments = [];
for ($index = 1; $index < count($argv); $index++) {
    if ($argv[$index] === '--json') continue;
    if ($argv[$index] === '--block') {
        $options['block'] = $argv[++$index] ?? '';
        continue;
    }
    $arguments[] = $argv[$index];
}
[$command, $source, $target, $extra] = $arguments + ['', '', '', ''];
$usage = static function () use ($script): never {
    fwrite(STDERR, "Usage:\n  php $script new <folder> [vendor/name] [--block <name>]\n  php $script validate <folder-or-zip> [--json]\n"
        . "  php $script render <folder-or-zip> <block> [props.json] [--json]\n  php $script pack <folder> [output.zip]\n");
    exit(2);
};
if (!in_array($command, ['new', 'validate', 'render', 'pack'], true) || $source === '' || ($command === 'pack' && !is_dir($source)) || ($command === 'render' && $target === '')) {
    $usage();
}

/** Machine-readable output for agents and CI: one JSON object on stdout. */
$emit = static function (array $result, int $code): never {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($code);
};

if ($command === 'new') {
    $folder = rtrim($source, '/');
    if (file_exists($folder) && (!is_dir($folder) || (scandir($folder) ?: []) !== ['.', '..'])) {
        fwrite(STDERR, "$folder already exists and is not empty." . PHP_EOL);
        exit(1);
    }
    $name = $target !== '' ? $target : 'my-vendor/' . (preg_replace('/[^a-z0-9-]+/', '-', strtolower(basename($folder))) ?: 'extension');
    $block = $options['block'] ?? 'hello';
    if (!Extension::validName($name) || !preg_match('/^[a-z][a-z0-9-]{0,39}$/D', $block)) {
        fwrite(STDERR, "Use a name like vendor/extension and a block name of lowercase letters, digits and hyphens." . PHP_EOL);
        exit(2);
    }
    // $schema paths point at this checkout's schemas, so editors and agents get completion and checks.
    $schemas = str_replace('\\', '/', dirname(__DIR__) . '/schemas');
    @mkdir($folder . '/blocks/' . $block, 0775, true);
    $relative = static function (string $from) use ($schemas): string {
        $from = realpath($from) ?: $from;
        $up = '';
        for ($directory = $from; !str_starts_with($schemas . '/', rtrim($directory, '/') . '/') && dirname($directory) !== $directory; $directory = dirname($directory)) $up .= '../';
        return $up . ltrim(substr($schemas, strlen(rtrim($directory, '/'))), '/');
    };
    [$vendor] = explode('/', $name);
    $title = ucwords(str_replace('-', ' ', explode('/', $name)[1]));
    $files = [
        'comet-extension.json' => [
            '$schema' => $relative($folder) . '/comet-extension.schema.json',
            'api_version' => 1, 'name' => $name, 'title' => $title, 'description' => 'Describe what this extension adds.', 'version' => '0.1.0',
            'shared_props' => ['accent' => ['type' => 'color', 'label' => 'Accent color', 'default' => 'primary', 'group' => 'Appearance']],
        ],
        "blocks/$block/block.json" => [
            '$schema' => $relative($folder . '/blocks/' . $block) . '/block.schema.json',
            'title' => ucwords(str_replace('-', ' ', $block)), 'description' => 'Describe the block for the palette.', 'icon' => 'mdi:puzzle-outline',
            'props' => [
                'title' => ['type' => 'string', 'label' => 'Heading', 'max' => 120, 'default' => 'Hello'],
                'body' => ['type' => 'richtext', 'label' => 'Text', 'default' => '<p>Write something.</p>'],
                'link' => ['type' => 'link', 'label' => 'Link'],
                'link_label' => ['type' => 'string', 'label' => 'Link label', 'max' => 60, 'default' => 'Learn more', 'visible_when' => ['link' => true]],
                'accent' => ['use' => 'accent'],
                'gap' => ['type' => 'space', 'label' => 'Spacing', 'default' => 'md', 'group' => 'Appearance'],
                'corners' => ['type' => 'radius', 'label' => 'Corners', 'default' => 'theme', 'group' => 'Appearance'],
            ],
        ],
        "blocks/$block/template.html" => <<<HTML
            <div class="$vendor-$block" style="--$vendor-$block-accent: {{accent}}; --$vendor-$block-gap: {{gap}}; --$vendor-$block-radius: {{corners}}">
              <h3 class="$vendor-{$block}__title">{{title}}</h3>
              <div class="$vendor-{$block}__body">{{{body}}}</div>
              {{#link}}<a class="$vendor-{$block}__link" href="{{link}}">{{link_label}}</a>{{/link}}
            </div>

            HTML,
        "blocks/$block/style.css" => <<<CSS
            /* Theme values arrive as custom properties; "initial" when unset, so the fallbacks apply. */
            .c-block-{$vendor}__$block .$vendor-$block {
              display: grid;
              gap: var(--$vendor-$block-gap, 1rem);
              padding: var(--$vendor-$block-gap, 1rem);
              border-inline-start: 4px solid var(--$vendor-$block-accent, var(--c-primary));
              border-radius: var(--$vendor-$block-radius, 0);
            }

            CSS,
    ];
    foreach ($files as $path => $content) {
        file_put_contents($folder . '/' . $path, is_array($content) ? json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL : $content);
    }
    if ($json) $emit(['ok' => true, 'folder' => $folder, 'name' => $name, 'files' => array_keys($files)], 0);
    echo "Created $name in $folder. Next: php $script validate $folder" . PHP_EOL;
    exit(0);
}

try {
    $files = is_dir($source) ? Extension::readDirectory(rtrim($source, '/')) : Extension::readZip($source);
    $extension = Extension::compile($files);
} catch (ExtensionException $e) {
    if ($json) $emit(['ok' => false, 'errors' => $e->errors()], 1);
    foreach ($e->errors() as $error) {
        fwrite(STDERR, ExtensionException::format($error) . PHP_EOL);
    }
    fwrite(STDERR, sprintf('%d %s found.%s', count($e->errors()), count($e->errors()) === 1 ? 'problem' : 'problems', PHP_EOL));
    exit(1);
}

$scripts = array_values(array_map(static fn(array $block): string => $block['name'], array_filter($extension['blocks'], static fn(array $block): bool => $block['script'] !== '')));
$blocks = implode(', ', array_map(static fn(string $kind): string => substr($kind, 6), array_keys($extension['blocks'])));
if ($scripts !== []) {
    $blocks .= ' (JavaScript: ' . implode(', ', $scripts) . ')';
}
$summary = implode(' · ', array_filter([$blocks, count($extension['seeds']) ? count($extension['seeds']) . ' site items' : '']));

if ($command === 'validate') {
    if ($json) {
        $emit(['ok' => true, 'name' => $extension['name'], 'version' => $extension['version'],
            'blocks' => array_map(static fn(string $kind): string => substr($kind, 6), array_keys($extension['blocks'])), 'scripts' => $scripts,
            'seeds' => array_map(static fn(array $seed): array => ['kind' => $seed['kind'], 'id' => $seed['id'], 'source' => $seed['source']], $extension['seeds'])], 0);
    }
    echo "OK {$extension['name']} {$extension['version']}: $summary" . PHP_EOL;
    exit(0);
}

if ($command === 'render') {
    // A quick look at a block's markup with its defaults, without a site: media, links and
    // menus get placeholders, theme values their CSS, the slot a comment.
    $kind = 'block:' . (str_contains($target, '/') ? $target : explode('/', $extension['name'])[0] . '/' . $target);
    $block = $extension['blocks'][$kind] ?? null;
    if ($block === null) {
        fwrite(STDERR, "No block $target. Blocks: $blocks" . PHP_EOL);
        exit(1);
    }
    $props = [];
    if ($extra !== '') {
        $props = json_decode((string) @file_get_contents($extra), true);
        if (!is_array($props)) {
            fwrite(STDERR, "$extra must hold a JSON object of prop values." . PHP_EOL);
            exit(1);
        }
    }
    $resolve = static function (array $specs, array $values) use (&$resolve): array {
        $resolved = [];
        foreach ($specs as $key => $spec) {
            $value = $values[$key] ?? $spec['default'];
            $resolved[$key] = match ($spec['type']) {
                'color', 'space', 'radius', 'shadow' => BlockTemplate::themeValue($spec['type'], $value),
                'media' => is_string($value) && $value !== '' ? ['_' => '/media/' . rawurlencode(basename($value)), 'alt' => 'Example image'] : ['_' => '', 'alt' => ''],
                'link' => is_array($value) && ($value['type'] ?? 'none') !== 'none' ? ['_' => (string) ($value['url'] ?? '#'), 'new_tab' => false, 'open_modal' => false] : (is_string($value) && $value !== '' ? ['_' => $value, 'new_tab' => false, 'open_modal' => false] : ['_' => '']),
                'menu' => is_string($value) && $value !== '' ? '<nav class="c-menu" data-menu aria-label="' . htmlspecialchars($value) . '"><ul class="c-menu-list"><li><span class="c-menu-label"><a href="/">Menu "' . htmlspecialchars($value) . '"</a></span></li></ul></nav>' : '',
                'list' => array_map(static fn(mixed $item): array => $resolve($spec['fields'], is_array($item) ? $item : []), is_array($value) ? array_values($value) : []),
                default => is_scalar($value) ? $value : '',
            };
        }
        return $resolved;
    };
    $settings = BlockScripts::settingValues($extension['settings'], []);
    $values = $resolve($block['props'], $props) + array_combine(array_map(static fn(string $key): string => '@' . $key, array_keys($settings)), array_values($settings));
    $assets = array_flip($extension['assets']);
    $html = '<div class="c-el ' . ExtensionRegistry::cssClass($block['name']) . '">'
        . BlockTemplate::render($block['template'], $values, static fn(string $file): string => $assets[$file] ?? $file, '<!-- child elements -->') . '</div>';
    if ($json) $emit(['ok' => true, 'block' => $block['name'], 'html' => $html, 'props' => $values], 0);
    echo $html . PHP_EOL;
    exit(0);
}

$target = $target !== '' ? $target : str_replace('/', '-', $extension['name']) . '-' . $extension['version'] . '.zip';
$zip = new ZipArchive();
if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Could not write $target." . PHP_EOL);
    exit(1);
}
foreach ($files as $path => $bytes) {
    $zip->addFromString($path, $bytes);
}
$zip->close();
echo "Packed {$extension['name']} {$extension['version']} ($summary) into $target" . PHP_EOL;
