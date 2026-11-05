<?php

declare(strict_types=1);

use CometCMS\Extensions\Extension;
use CometCMS\Site\Shadow;

function comet_extension_schema(string $name): array
{
    return json_decode((string) file_get_contents(COMET_TEST_ROOT . '/schemas/' . $name . '.schema.json'), true, flags: JSON_THROW_ON_ERROR);
}

/** The "then" branch of a prop schema's type condition. */
function comet_prop_schema_branch(array $prop, string $type): array
{
    foreach ($prop['allOf'] as $rule) {
        if (($rule['if']['properties']['type']['const'] ?? null) === $type) return $rule['then'];
    }
    throw new RuntimeException('No schema branch for ' . $type . '.');
}

/** Runs the extension CLI; returns [exit code, stdout]. */
function comet_extension_cli(string ...$arguments): array
{
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(COMET_TEST_ROOT . '/scripts/extension.php') . ' ' . implode(' ', array_map('escapeshellarg', $arguments)) . ' 2>&1';
    exec($command, $output, $code);

    return [$code, implode("\n", $output)];
}

test('the JSON schemas describe the same fields, prop types and theme values as the compiler', function (): void {
    $manifest = comet_extension_schema('comet-extension');
    $block = comet_extension_schema('block');
    $prop = $block['definitions']['prop'];
    $sorted = static function (array $values): array {
        sort($values);
        return $values;
    };
    assert_same($sorted(Extension::EXTENSION_FIELDS), $sorted(array_keys($manifest['properties'])));
    assert_same($sorted(Extension::BLOCK_FIELDS), $sorted(array_keys($block['properties'])));
    assert_same($sorted(Extension::PROP_FIELDS), $sorted(array_keys($prop['properties'])));
    assert_same(Extension::PROP_TYPES, $prop['properties']['type']['enum']);
    assert_same(Extension::SETTING_TYPES, $manifest['properties']['settings']['additionalProperties']['properties']['type']['enum']);
    assert_same(['', ...Extension::COLOR_KEYS], comet_prop_schema_branch($prop, 'color')['properties']['default']['enum']);
    assert_same(Extension::themeOptions('space'), comet_prop_schema_branch($prop, 'space')['properties']['default']['enum']);
    assert_same(Extension::themeOptions('radius'), comet_prop_schema_branch($prop, 'radius')['properties']['default']['enum']);
    assert_same(Shadow::FIXED, comet_prop_schema_branch($prop, 'shadow')['properties']['default']['enum']);
});

test('the extension CLI scaffolds, validates, renders and reports errors as JSON', function (): void {
    $folder = COMET_STORAGE . '/cli-' . bin2hex(random_bytes(4));
    [$code, $output] = comet_extension_cli('new', $folder, 'acme/demo', '--block', 'greeting', '--json');
    assert_same(0, $code, $output);
    assert_same(['comet-extension.json', 'blocks/greeting/block.json', 'blocks/greeting/template.html', 'blocks/greeting/style.css'], json_decode($output, true)['files']);
    assert_true(str_ends_with(json_decode((string) file_get_contents($folder . '/comet-extension.json'), true)['$schema'], 'schemas/comet-extension.schema.json'));
    assert_same(1, comet_extension_cli('new', $folder)[0], 'An existing folder is never overwritten.');

    [$code, $output] = comet_extension_cli('validate', $folder, '--json');
    assert_same(0, $code, $output);
    assert_same(['ok' => true, 'name' => 'acme/demo', 'version' => '0.1.0', 'blocks' => ['acme/greeting'], 'backgrounds' => [], 'scripts' => [], 'seeds' => []], json_decode($output, true));

    [$code, $output] = comet_extension_cli('render', $folder, 'greeting', '--json');
    assert_same(0, $code, $output);
    $html = json_decode($output, true)['html'];
    assert_true(str_contains($html, 'class="c-el c-block-acme__greeting"'), $html);
    assert_true(str_contains($html, '--acme-greeting-gap:var(--space-md)'), $html);
    assert_true(str_contains($html, '<h3 class="acme-greeting__title">Hello</h3>'), $html);

    file_put_contents($folder . '/blocks/greeting/block.json', json_encode(['title' => 'Greeting', 'props' => ['gap' => ['use' => 'missing']]]));
    [$code, $output] = comet_extension_cli('validate', $folder, '--json');
    assert_same(1, $code);
    assert_same(['ok' => false, 'errors' => [
        ['file' => 'blocks/greeting/block.json', 'line' => null, 'message' => 'props.gap: use: there is no shared prop "missing". Declare it in comet-extension.json under shared_props, which has: accent.'],
    ]], json_decode($output, true));
});

test('MCP lists installed extensions with the blocks agents can place and their theme value props', function (): void {
    [$token] = comet_test_builder_setup();
    $registry = \CometCMS\Extensions\ExtensionRegistry::active();
    $registry->installFiles(Extension::readDirectory(COMET_TEST_ROOT . '/examples/extensions/comet-menu-variations'));
    [, $result] = comet_test_mcp_tool($token, 'list_extensions', []);
    $extension = $result['data']['extensions'][0];
    assert_same(['cometcms/menu-variations', '1.1.0', true], [$extension['name'], $extension['version'], $extension['enabled']]);
    $pills = array_column($extension['blocks'], null, 'kind')['block:cometcms/menu-pills'];
    assert_same(['title' => 'Pill Navigation', 'category' => 'Navigation', 'slot' => false, 'script' => false, 'deprecated' => false],
        array_intersect_key($pills, array_flip(['title', 'category', 'slot', 'script', 'deprecated'])));
    assert_same([], $result['data']['failed']);

    // A radius prop reads as the Style panel's radius tokens.
    [, $schema] = comet_test_mcp_tool($token, 'get_builder_schema', ['kind' => 'block:cometcms/menu-pills']);
    $corners = $schema['data']['props']['corners'];
    assert_same(['enum', ['', 'none', 'theme', 'sm', 'md', 'lg', 'full'], 'full'], [$corners['type'], $corners['options'], $corners['default']]);
    $registry->setEnabled('cometcms/menu-variations', false);
    [, $result] = comet_test_mcp_tool($token, 'list_extensions', []);
    assert_false($result['data']['extensions'][0]['enabled']);
});
