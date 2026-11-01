<?php

// Validate or pack a block package without a running CMS, with the same checks as installation.
//   php scripts/block-package.php validate <folder-or-zip>
//   php scripts/block-package.php pack <folder> [output.zip]

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $path = dirname(__DIR__) . '/cms/app/' . str_replace('\\', '/', substr($class, strlen('CometCMS\\'))) . '.php';
    if (str_starts_with($class, 'CometCMS\\') && is_file($path)) {
        require $path;
    }
});

use CometCMS\Site\Blocks\BlockPackage;
use CometCMS\Site\Blocks\BlockPackageException;

[$script, $command, $source, $target] = $argv + [null, '', '', ''];
if (!in_array($command, ['validate', 'pack'], true) || $source === '' || ($command === 'pack' && !is_dir($source))) {
    fwrite(STDERR, "Usage:\n  php $script validate <folder-or-zip>\n  php $script pack <folder> [output.zip]\n");
    exit(2);
}

try {
    $files = is_dir($source) ? BlockPackage::readDirectory(rtrim($source, '/')) : BlockPackage::readZip($source);
    $package = BlockPackage::compile($files);
} catch (BlockPackageException $e) {
    foreach ($e->errors() as $error) {
        fwrite(STDERR, BlockPackageException::format($error) . PHP_EOL);
    }
    fwrite(STDERR, sprintf('%d %s found.%s', count($e->errors()), count($e->errors()) === 1 ? 'problem' : 'problems', PHP_EOL));
    exit(1);
}

$blocks = implode(', ', array_map(static fn(string $kind): string => substr($kind, 6), array_keys($package['blocks'])));
$scripts = array_map(static fn(array $block): string => $block['name'], array_filter($package['blocks'], static fn(array $block): bool => $block['script'] !== ''));
if ($scripts !== []) {
    $blocks .= ' (JavaScript: ' . implode(', ', $scripts) . ')';
}
if ($command === 'validate') {
    echo "OK {$package['name']} {$package['version']}: $blocks" . PHP_EOL;
    exit(0);
}

$target = $target !== '' ? $target : str_replace('/', '-', $package['name']) . '-' . $package['version'] . '.zip';
$zip = new ZipArchive();
if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Could not write $target." . PHP_EOL);
    exit(1);
}
foreach ($files as $path => $bytes) {
    $zip->addFromString($path, $bytes);
}
$zip->close();
echo "Packed {$package['name']} {$package['version']} ($blocks) into $target" . PHP_EOL;
