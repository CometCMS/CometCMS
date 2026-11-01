<?php

// Validate or pack an extension without a running CMS, with the compiler checks. Site-dependent seed validation runs on installation.
//   php scripts/extension.php validate <folder-or-zip>
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

[$script, $command, $source, $target] = $argv + [null, '', '', ''];
if (!in_array($command, ['validate', 'pack'], true) || $source === '' || ($command === 'pack' && !is_dir($source))) {
    fwrite(STDERR, "Usage:\n  php $script validate <folder-or-zip>\n  php $script pack <folder> [output.zip]\n");
    exit(2);
}

try {
    $files = is_dir($source) ? Extension::readDirectory(rtrim($source, '/')) : Extension::readZip($source);
    $extension = Extension::compile($files);
} catch (ExtensionException $e) {
    foreach ($e->errors() as $error) {
        fwrite(STDERR, ExtensionException::format($error) . PHP_EOL);
    }
    fwrite(STDERR, sprintf('%d %s found.%s', count($e->errors()), count($e->errors()) === 1 ? 'problem' : 'problems', PHP_EOL));
    exit(1);
}

$blocks = implode(', ', array_map(static fn(string $kind): string => substr($kind, 6), array_keys($extension['blocks'])));
$scripts = array_map(static fn(array $block): string => $block['name'], array_filter($extension['blocks'], static fn(array $block): bool => $block['script'] !== ''));
if ($scripts !== []) {
    $blocks .= ' (JavaScript: ' . implode(', ', $scripts) . ')';
}
$summary = implode(' · ', array_filter([$blocks, count($extension['seeds']) ? count($extension['seeds']) . ' site items' : '']));
if ($command === 'validate') {
    echo "OK {$extension['name']} {$extension['version']}: $summary" . PHP_EOL;
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
