<?php

declare(strict_types=1);

use CometCMS\Core\ApiResponder;
use CometCMS\Core\Http;
use CometCMS\Core\ValidationException;

function comet_api_responder_test_run_inline_php(string $code): string
{
    $command = 'php -r ' . escapeshellarg($code);
    $output = shell_exec($command);

    if (!is_string($output)) {
        throw new RuntimeException('Failed to run inline PHP command for API responder test.');
    }

    return $output;
}

function comet_api_responder_test_bootstrap_require_snippet(): string
{
    return 'require ' . var_export(__DIR__ . '/bootstrap.php', true) . ';';
}

test('api responder data wraps payload in data envelope', function (): void {
    $bootstrapRequire = comet_api_responder_test_bootstrap_require_snippet();

    $output = comet_api_responder_test_run_inline_php(
        $bootstrapRequire .
            'register_shutdown_function(static function (): void {' .
            'echo "\n__CODE__" . http_response_code();' .
            '});' .
            '(new \\CometCMS\\Core\\ApiResponder(new \\CometCMS\\Core\\Http()))->data(["id" => 1], 200);'
    );

    assert_true(str_contains($output, '"data": {'));
    assert_true(str_contains($output, '"id": 1'));
    assert_true(str_contains($output, '__CODE__200'));
});

test('api responder data includes meta when provided', function (): void {
    $bootstrapRequire = comet_api_responder_test_bootstrap_require_snippet();

    $output = comet_api_responder_test_run_inline_php(
        $bootstrapRequire .
            '(new \\CometCMS\\Core\\ApiResponder(new \\CometCMS\\Core\\Http()))->data(["items" => []], 206, ["page" => 2]);'
    );

    assert_true(str_contains($output, '"data": {'));
    assert_true(str_contains($output, '"meta": {'));
    assert_true(str_contains($output, '"page": 2'));
});

test('api responder error returns code message and fields payload', function (): void {
    $bootstrapRequire = comet_api_responder_test_bootstrap_require_snippet();

    $output = comet_api_responder_test_run_inline_php(
        $bootstrapRequire .
            '(new \\CometCMS\\Core\\ApiResponder(new \\CometCMS\\Core\\Http()))->error(' .
            '"validation_failed", "Validation failed", 422, ["email" => ["invalid"]]' .
            ');'
    );

    assert_true(str_contains($output, '"error": {'));
    assert_true(str_contains($output, '"code": "validation_failed"'));
    assert_true(str_contains($output, '"message": "Validation failed"'));
    assert_true(str_contains($output, '"fields": {'));
    assert_true(str_contains($output, '"email": ['));
});

test('validation exception exposes status code 422 and fields accessor', function (): void {
    $exception = new ValidationException(['title' => ['required']], 'Invalid entry.');

    assert_same(422, $exception->getCode());
    assert_same('Invalid entry.', $exception->getMessage());
    assert_same(['title' => ['required']], $exception->fields());
});
