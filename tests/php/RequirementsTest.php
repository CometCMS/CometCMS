<?php

declare(strict_types=1);

use CometCMS\Core\Requirements;

test('missing PHP requirements are named with an install hint', function (): void {
    assert_same([], Requirements::missing('8.3.6', static fn(): bool => true));
    $missing = Requirements::missing('8.1.0', static fn(string $extension): bool => $extension !== 'mbstring');
    assert_same(['PHP 8.2.0 or later', 'the PHP extension "mbstring"'], $missing);
    $hint = Requirements::hint(['the PHP extension "mbstring"']);
    assert_true(str_contains($hint, 'This server lacks the PHP extension "mbstring". Install it and restart PHP or the web server.'));
    assert_true(str_contains($hint, 'sudo apt install php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '-mbstring'));
});
