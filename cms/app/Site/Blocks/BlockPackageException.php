<?php

declare(strict_types=1);

namespace CometCMS\Site\Blocks;

/** Every problem found in a package, each tied to the file (and line) that caused it. */
final class BlockPackageException extends \InvalidArgumentException
{
    /** @param list<array{file: string, line: ?int, message: string}> $errors */
    public function __construct(private readonly array $errors)
    {
        $count = count($errors);
        parent::__construct(self::format($errors[0] ?? ['file' => '', 'line' => null, 'message' => 'Invalid block package.'])
            . ($count > 1 ? sprintf(' (and %d more %s)', $count - 1, $count === 2 ? 'problem' : 'problems') : ''));
    }

    public static function single(string $file, string $message, ?int $line = null): self
    {
        return new self([['file' => $file, 'line' => $line, 'message' => $message]]);
    }

    /** @return list<array{file: string, line: ?int, message: string}> */
    public function errors(): array
    {
        return $this->errors;
    }

    public static function format(array $error): string
    {
        $location = $error['file'] . ($error['line'] !== null ? ':' . $error['line'] : '');

        return ($location !== '' ? $location . ': ' : '') . $error['message'];
    }
}
