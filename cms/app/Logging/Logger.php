<?php

declare(strict_types=1);

namespace CometCMS\Logging;

use CometCMS\Core\Security;
use CometCMS\Workspaces\WorkspaceContext;

final class Logger
{
    public function __construct(private readonly string $path = COMET_STORAGE . '/logs/comet.log')
    {
        if (!is_dir(dirname($this->path))) {
            @mkdir(dirname($this->path), 0775, true);
        }
    }

    public function info(string $message, array $context = []): void
    {
        $this->write('info', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->write('warning', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->write('error', $message, $context);
    }

    private function write(string $level, string $message, array $context): void
    {
        $this->rotateIfNeeded();
        unset($context['password'], $context['token'], $context['api_token'], $context['secret']);

        $ws = WorkspaceContext::active()->slug();
        if ($ws !== '') {
            $context = array_merge(['workspace' => $ws], $context);
        }

        $line = json_encode([
            'time' => Security::now(),
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ], JSON_UNESCAPED_SLASHES);

        if ($line !== false && @file_put_contents($this->path, $line . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
            error_log('CometCMS log write failed: ' . $line);
        }
    }

    private function rotateIfNeeded(): void
    {
        if (is_file($this->path) && filesize($this->path) !== false && filesize($this->path) > 1024 * 1024) {
            @rename($this->path, $this->path . '.' . gmdate('YmdHis'));
        }
    }
}
