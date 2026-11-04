<?php

declare(strict_types=1);

use CometCMS\Auth\UserRepository;

test('users store a red theme preference', function (): void {
    $users = new UserRepository();
    $user = $users->create('admin', 'secret-password', 'admin');

    $updated = $users->update((string) $user['id'], ['theme' => 'red']);

    assert_same('red', $updated['theme']);
    assert_same('red', $users->find((string) $user['id'])['theme'] ?? null);
});

test('users combine any theme color with a light, dark or auto color mode', function (): void {
    $users = new UserRepository();
    $user = $users->create('admin', 'secret-password', 'admin');

    assert_same(['theme' => 'blue', 'color_mode' => 'light'], UserRepository::appearance($user));

    $updated = $users->update((string) $user['id'], ['theme' => 'green', 'color_mode' => 'dark']);
    assert_same(['theme' => 'green', 'color_mode' => 'dark'], UserRepository::appearance($updated));

    $updated = $users->update((string) $user['id'], ['color_mode' => 'auto']);
    assert_same(['theme' => 'green', 'color_mode' => 'auto'], UserRepository::appearance($users->find((string) $user['id'])));

    assert_throws(\InvalidArgumentException::class, fn() => $users->update((string) $user['id'], ['color_mode' => 'sepia']));
    assert_throws(\InvalidArgumentException::class, fn() => $users->update((string) $user['id'], ['theme' => 'pink']));
});

test('the former dark theme becomes dark mode with the default color', function (): void {
    $users = new UserRepository();
    $user = $users->create('admin', 'secret-password', 'admin');
    $user['theme'] = 'dark';
    unset($user['color_mode']);
    $users->save($user);

    assert_same(['theme' => 'blue', 'color_mode' => 'dark'], UserRepository::appearance($user));

    // Picking a color keeps the dark mode the user had chosen.
    $updated = $users->update((string) $user['id'], ['theme' => 'red']);
    assert_same('red', $updated['theme']);
    assert_same('dark', $updated['color_mode']);

    // Older clients may still send the combined theme.
    $updated = $users->update((string) $user['id'], ['theme' => 'dark', 'color_mode' => null]);
    assert_same(['theme' => 'red', 'color_mode' => 'dark'], UserRepository::appearance($updated));
});

test('users store an admin language preference', function (): void {
    $users = new UserRepository();
    $user = $users->create('admin', 'secret-password', 'admin');

    assert_same('en', $user['language']);

    $updated = $users->update((string) $user['id'], ['language' => 'pt-BR']);

    assert_same('pt-br', $updated['language']);
    assert_same('pt-br', $users->find((string) $user['id'])['language'] ?? null);
});

test('users reject invalid admin language tags', function (): void {
    $users = new UserRepository();
    $user = $users->create('admin', 'secret-password', 'admin');

    assert_throws(InvalidArgumentException::class, function () use ($users, $user): void {
        $users->update((string) $user['id'], ['language' => '../de']);
    });
});

test('the last admin can be neither demoted nor deleted', function (): void {
    $users = new UserRepository();
    $users->create('owner', 'password123', 'admin');
    $users->create('writer', 'password123', 'editor');

    foreach ([
        static fn() => $users->update('owner', ['role' => 'editor']),
        static fn() => $users->delete('owner'),
    ] as $attempt) {
        $failed = false;
        try {
            $attempt();
        } catch (InvalidArgumentException) {
            $failed = true;
        }
        assert_true($failed, 'Expected the last admin to be protected.');
    }
    assert_same('admin', $users->find('owner')['role']);

    // Once another admin exists, either one may step down or be removed.
    $users->update('writer', ['role' => 'admin']);
    $users->update('owner', ['role' => 'editor']);
    $users->delete('owner');
    assert_same(1, $users->adminCount());
});
