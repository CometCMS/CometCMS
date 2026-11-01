<?php

declare(strict_types=1);

use CometCMS\Core\ValidationException;
use CometCMS\Media\MediaRepository;

function comet_media_test_file(string $name, string $body = 'file'): void
{
    file_put_contents(comet_test_workspace_path() . '/media/' . $name, $body);
}

test('media listing shares one permission scope across pagination and category statistics', function (): void {
    foreach (['hero.jpg', 'guide.pdf', 'hidden.pdf', 'song.mp3'] as $file) comet_media_test_file($file);
    $repository = new MediaRepository();
    $repository->assignCategory('hero.jpg', 'Images / Heroes');
    $repository->assignCategory('guide.pdf', 'Docs / Guides');
    $repository->assignCategory('hidden.pdf', 'Docs / Private');
    $repository->updateVisibility('hidden.pdf', 'private');
    $checks = 0;
    $result = $repository->listing('', 'Docs', 1, 0, 'documents', 'name', null, null,
        static function (array $context) use (&$checks): bool {
            $checks++;
            return $context['file'] !== 'hidden.pdf';
        });
    assert_same(4, $checks, 'Each file is authorized once for the listing and stats together.');
    assert_same(['guide.pdf'], array_column($result['data'], 'filename'));
    assert_same(1, $result['meta']['total']);
    assert_same(3, $result['meta']['stats']['total']);
    assert_same(1, $result['meta']['stats']['uncategorized']);
    assert_same(['Docs' => 1, 'Docs / Guides' => 1, 'Images' => 1, 'Images / Heroes' => 1], $result['meta']['stats']['categories']);
    $repository->assignCategory('song.mp3', 'Music');
    assert_same(1, $repository->listing()['meta']['stats']['categories']['Music']);
});

test('media repository manages nested categories and updates assigned files', function (): void {
    comet_media_test_file('hero.jpg');
    $repository = new MediaRepository();

    assert_same(['Images', 'Images / Heroes'], $repository->addCategory('Images / Heroes'));
    $item = $repository->assignCategory('hero.jpg', 'Images / Heroes');

    assert_same('Images / Heroes', $item['category']);

    assert_same(['Assets', 'Assets / Heroes'], $repository->renameCategory('Images', 'Assets'));
    assert_same('Assets / Heroes', $repository->item('hero.jpg')['category']);

    assert_same([], $repository->deleteCategory('Assets'));
    assert_same('', $repository->item('hero.jpg')['category']);
});

test('media repository filters sorts and paginates files', function (): void {
    comet_media_test_file('hero.jpg');
    comet_media_test_file('song.mp3');
    comet_media_test_file('guide.pdf');
    $repository = new MediaRepository();

    $repository->assignCategory('guide.pdf', 'Docs');

    assert_same(['hero.jpg'], array_column($repository->files('', null, 'images', 'name'), 'name'));
    assert_same(['guide.pdf'], array_column($repository->files('guide', 'Docs', 'documents', 'name'), 'name'));
    assert_same(['hero.jpg', 'song.mp3'], array_column($repository->limitedFiles('', null, 2, 1, 'all', 'name')['data'], 'name'));
    assert_same(3, $repository->limitedFiles('', null, 2, 0, 'all', 'name')['meta']['total']);
    assert_same(['song.mp3'], array_column($repository->limitedFiles('', null, 2, 0, 'all', 'name', null, ['guide.pdf' => [], 'hero.jpg' => []])['data'], 'name'));
    assert_same(['total' => 3, 'uncategorized' => 2, 'categories' => ['Docs' => 1]], $repository->stats());
});

test('media repository preserves metadata when renaming and supports bulk updates', function (): void {
    comet_media_test_file('old.jpg');
    comet_media_test_file('other.jpg');
    $repository = new MediaRepository();

    $repository->assignCategory('old.jpg', 'Images');
    $repository->updateMeta('old.jpg', 'Hero alt', 'Hero title');
    $repository->updateVisibility('old.jpg', 'private');

    $renamed = $repository->rename('old.jpg', 'hero');

    assert_same('hero.jpg', $renamed['name']);
    assert_same('Images', $renamed['category']);
    assert_same('Hero alt', $renamed['alt']);
    assert_same('Hero title', $renamed['title']);
    assert_same('private', $renamed['visibility']);
    assert_false(is_file(comet_test_workspace_path() . '/media/old.jpg'));

    assert_same(['hero.jpg', 'other.jpg'], array_column($repository->updateVisibilityForMany(['hero.jpg', 'other.jpg', 'missing.jpg'], 'private'), 'name'));
    assert_same(['hero.jpg', 'other.jpg'], array_column($repository->files('', null, 'all', 'name', 'private'), 'name'));

    assert_same(['hero.jpg', 'other.jpg'], array_column($repository->assignCategoryToMany(['hero.jpg', 'other.jpg'], 'Shared'), 'name'));
    assert_same(['Images', 'Shared'], $repository->categories());
});

test('media repository generates cached variants without upscaling originals', function (): void {
    if (!function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) {
        return;
    }

    $path = comet_test_workspace_path() . '/media/landscape.jpg';
    $source = imagecreatetruecolor(1200, 600);
    imagejpeg($source, $path, 90);
    unset($source);
    $repository = new MediaRepository();

    $variant = $repository->variant('landscape.jpg', ['w' => 400, 'format' => 'jpeg']);
    assert_same(400, $variant['width']);
    assert_same(200, $variant['height']);
    assert_same('image/jpeg', $variant['mime']);
    assert_file_exists_at($variant['path']);
    assert_same($variant['path'], $repository->variant('landscape.jpg', ['w' => 400, 'format' => 'jpeg'])['path']);

    $large = $repository->variant('landscape.jpg', ['w' => 1920, 'format' => 'jpeg']);
    assert_same(1200, $large['width']);
    assert_same(600, $large['height']);
    assert_throws(InvalidArgumentException::class, fn(): array => $repository->variant('landscape.jpg', ['w' => 333]));

    global $cometConfig;
    $cometConfig['media']['variants']['allow_custom_sizes'] = true;
    try {
        $cover = $repository->variant('landscape.jpg', ['w' => 400, 'h' => 400, 'fit' => 'cover', 'format' => 'jpeg']);
        assert_same(400, $cover['width']);
        assert_same(400, $cover['height']);
    } finally {
        $cometConfig['media']['variants']['allow_custom_sizes'] = false;
    }

    $descriptor = $repository->variantDescriptor('landscape.jpg');
    assert_same([400, 960], $descriptor['widths']);
    assert_true(isset($descriptor['formats'][0]));
});

test('media variant cache is disposable and removed with its original', function (): void {
    if (!function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) {
        return;
    }

    $path = comet_test_workspace_path() . '/media/photo.jpg';
    $source = imagecreatetruecolor(800, 400);
    imagejpeg($source, $path, 90);
    unset($source);
    $repository = new MediaRepository();
    $variant = $repository->variant('photo.jpg', ['w' => 400, 'format' => 'jpeg']);
    $cacheDirectory = dirname($variant['path']);

    $repository->delete('photo.jpg');

    assert_false(is_file($path));
    assert_false(is_dir($cacheDirectory));
});

test('media thumbnails are stored as variants', function (): void {
    if (!function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) {
        return;
    }

    $path = comet_test_workspace_path() . '/media/thumbnail.jpg';
    $source = imagecreatetruecolor(800, 400);
    imagejpeg($source, $path, 90);
    unset($source);
    $repository = new MediaRepository();

    $thumbnail = $repository->thumbnailPath('thumbnail.jpg');

    assert_true(is_string($thumbnail));
    assert_true(str_starts_with($thumbnail, comet_test_workspace_path() . '/media-variants/'));
    assert_false(is_dir(comet_test_workspace_path() . '/media-thumbs'));
});

test('uploads get every named size smaller than the original, proportions kept', function (): void {
    if (!function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) {
        return;
    }

    $source = imagecreatetruecolor(1000, 2000);
    imagejpeg($source, comet_test_workspace_path() . '/media/portrait.jpg', 90);
    unset($source);
    $repository = new MediaRepository();

    assert_same(['thumbnail' => ['width' => 200, 'height' => 400], 'medium' => ['width' => 480, 'height' => 960], 'large' => ['width' => 960, 'height' => 1920]], $repository->imageSizes('portrait.jpg'));
    assert_same($repository->imageSizes('portrait.jpg'), $repository->find('portrait.jpg')['sizes']);
    assert_true($repository->generateSizes('portrait.jpg'));
    assert_same(3, count(glob(comet_test_workspace_path() . '/media-variants/*/*') ?: []));

    $medium = $repository->sizeVariant('portrait.jpg', 'medium', 'jpeg');
    assert_same([480, 960], array_slice(getimagesize($medium['path']) ?: [], 0, 2));
    assert_null($repository->sizeVariant('portrait.jpg', 'huge'));
});

test('a named size the original does not exceed is the original', function (): void {
    if (!function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) {
        return;
    }

    $path = comet_test_workspace_path() . '/media/small.jpg';
    $source = imagecreatetruecolor(600, 300);
    imagejpeg($source, $path, 90);
    unset($source);
    $repository = new MediaRepository();

    assert_same(['thumbnail'], array_keys($repository->imageSizes('small.jpg')));
    assert_same($path, $repository->sizePath('small.jpg', 'large'));
    assert_same([], $repository->imageSizes('notes.txt'));
    file_put_contents(comet_test_workspace_path() . '/media/notes.txt', 'text');
    assert_same(['small.jpg'], $repository->resizableFiles());
});

test('workspace image sizes are bounded and must grow', function (): void {
    $sizes = (new MediaRepository())->sizes();

    assert_same(['thumbnail' => 400, 'medium' => 960, 'large' => 1920], $sizes->all());
    assert_same(['thumbnail' => 300, 'medium' => 800, 'large' => 1600], $sizes->save(['thumbnail' => 300, 'medium' => '800', 'large' => 1600]));
    assert_same(['thumbnail' => 300, 'medium' => 800, 'large' => 1600], (new MediaRepository())->sizes()->all());
    assert_throws(ValidationException::class, fn() => $sizes->save(['thumbnail' => 900, 'medium' => 800, 'large' => 1600]));
    assert_throws(ValidationException::class, fn() => $sizes->save(['thumbnail' => 10, 'medium' => 800, 'large' => 1600]));
    assert_throws(ValidationException::class, fn() => $sizes->save(['thumbnail' => 300, 'medium' => 800, 'large' => 9000]));
});
