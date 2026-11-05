<?php

declare(strict_types=1);

use CometCMS\Site\BackgroundVideo;
use CometCMS\Site\LayoutSchema;
use CometCMS\Site\SiteScripts;
use CometCMS\Site\SiteService;
use CometCMS\Site\NodeRenderer;
use CometCMS\Site\VideoEmbed;

test('video elements normalize options and survive layout round trips', function (): void {
    $video = LayoutSchema::node('video', ['video_url' => 'https://youtu.be/dQw4w9WgXcQ', 'ratio' => '9:16', 'start_time' => -2, 'controls' => false, 'autoplay' => true]);
    $layout = LayoutSchema::normalizeComponent([LayoutSchema::node('column', [], [], [$video])], 'column');
    $props = $layout[0]['children'][0]['props'];
    assert_same('9:16', $props['ratio']);
    assert_same(0, $props['start_time']);
    assert_false($props['controls']);
    assert_true($props['autoplay']);
    assert_same($layout, LayoutSchema::normalizeComponent($layout, 'column'));
    assert_true(in_array('video', LayoutSchema::spec()['elements'], true));
    assert_true(in_array('video', LayoutSchema::ITEM_ELEMENTS, true));
});

test('YouTube video links use a canonical privacy embed and explicit playback settings', function (): void {
    foreach (['https://youtu.be/dQw4w9WgXcQ?t=50', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ&autoplay=1', 'https://m.youtube.com/shorts/dQw4w9WgXcQ', 'https://youtube.com/live/dQw4w9WgXcQ', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'] as $url) {
        $source = VideoEmbed::source(['video_url' => $url, 'autoplay' => true, 'controls' => false, 'loop' => true, 'fullscreen' => false, 'start_time' => 12]);
        assert_same('iframe', $source['type']);
        assert_true(str_starts_with($source['url'], 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?'));
        parse_str(parse_url($source['url'], PHP_URL_QUERY), $params);
        foreach (['autoplay' => '1', 'mute' => '1', 'controls' => '0', 'loop' => '1', 'fs' => '0', 'start' => '12', 'playlist' => 'dQw4w9WgXcQ'] as $key => $expected) assert_same($expected, $params[$key]);
    }
});

test('Vimeo video links retain unlisted hashes without accepting arbitrary parameters', function (): void {
    foreach (['https://vimeo.com/76979871/abc123', 'https://player.vimeo.com/video/76979871?h=abc123&autoplay=1'] as $url) {
        $source = VideoEmbed::source(['video_url' => $url, 'start_time' => 42]);
        assert_true(str_starts_with($source['url'], 'https://player.vimeo.com/video/76979871?'));
        assert_true(str_contains($source['url'], 'h=abc123'));
        assert_true(str_contains($source['url'], 'autoplay=0'));
        assert_true(str_ends_with($source['url'], '#t=42s'));
    }
    assert_same('https://cdn.example.com/movie.webm?signature=abc#t=20', VideoEmbed::source(['video_url' => 'https://cdn.example.com/movie.webm?signature=abc#t=99', 'start_time' => 20])['url']);
});

test('video embeds reject untrusted frame sources and malformed input', function (): void {
    foreach (['javascript:alert(1)', 'data:text/html,test', '//youtube.com/watch?v=dQw4w9WgXcQ', 'https://youtube.com.evil.test/watch?v=dQw4w9WgXcQ', 'https://youtube.com@evil.test/embed/dQw4w9WgXcQ', 'https://user:pass@youtube.com/watch?v=dQw4w9WgXcQ', 'https://youtube.com:443/watch?v=dQw4w9WgXcQ', 'https://youtube.com/watch?v[]=bad', 'https://vimeo.com/not-a-video', 'https://evil.test/embed/123', 'http://cdn.example.com/video.mp4', 'https://cdn.example.com/video.html', "https://youtube.com/\nwatch?v=dQw4w9WgXcQ"] as $url) {
        assert_null(VideoEmbed::source(['video_url' => $url]), $url);
    }
});

test('video rendering escapes labels and preserves selection without loading media in the canvas', function (): void {
    comet_site_test_setup();
    $ctx = comet_site_test_context();
    $renderer = new NodeRenderer($ctx);
    $video = LayoutSchema::node('video', ['video_url' => 'https://youtu.be/dQw4w9WgXcQ', 'title' => '"><script>alert(1)</script>', 'ratio' => '9:16', 'autoplay' => true, 'fullscreen' => false]);
    $html = $renderer->render([$video]);
    assert_true(str_contains($html, '<iframe'));
    assert_true(str_contains($html, 'c-ratio-9-16'));
    assert_true(str_contains($html, 'title="&quot;&gt;&lt;script&gt;'));
    assert_false(str_contains($html, '<script>'));
    assert_false(str_contains($html, 'allowfullscreen'));
    assert_true(str_contains($html, 'loading="eager"'));
    $ctx->preview = true;
    $ctx->annotate = true;
    $html = $renderer->render([$video]);
    assert_true(str_contains($html, 'data-node="' . $video['id'] . '"'));
    assert_true(str_contains($html, 'Video preview:'));
    assert_true(str_contains($html, 'c-video-badge'));
    assert_true(str_contains($html, 'YouTube</span>'));
    assert_false(str_contains($html, '<iframe'));
    $ctx->annotate = false;
    $ctx->browsePreview = true;
    assert_true(str_contains($renderer->render([$video]), '<iframe'));
    $video['props']['video_url'] = 'https://invalid.example/';
    assert_same('', $renderer->render([$video]));
    $ctx->annotate = true;
    assert_true(str_contains($renderer->render([$video]), 'YouTube, Vimeo or direct video URL'));
});

test('direct video files render native playback options and time fragments', function (): void {
    comet_site_test_setup();
    $video = LayoutSchema::node('video', ['video_url' => 'https://cdn.example.com/movie.mp4?token=abc&other=value', 'autoplay' => true, 'controls' => false, 'loop' => true, 'fullscreen' => false, 'start_time' => 5]);
    $html = (new NodeRenderer(comet_site_test_context()))->render([$video]);
    assert_true(str_contains($html, '<video src="https://cdn.example.com/movie.mp4?token=abc&amp;other=value#t=5"'));
    assert_true(str_contains($html, ' muted autoplay'));
    assert_true(str_contains($html, ' loop'));
    assert_true(str_contains($html, 'controlslist="nofullscreen"'));
    assert_false(str_contains($html, ' controls '));
    assert_false(str_contains($html, '<iframe'));
});

test('library videos take precedence over URLs and use an image poster', function (): void {
    comet_site_test_setup();
    file_put_contents(comet_test_workspace_path() . '/media/clip.mp4', "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom");
    file_put_contents(comet_test_workspace_path() . '/media/photo.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="40"></svg>');
    $video = LayoutSchema::node('video', ['media' => 'clip.mp4', 'poster' => 'photo.svg', 'video_url' => 'https://youtu.be/dQw4w9WgXcQ', 'start_time' => 3]);
    $html = (new NodeRenderer(comet_site_test_context()))->render([$video]);
    assert_true(str_contains($html, '<video src="'), $html);
    assert_true(str_contains($html, 'clip.mp4#t=3'));
    assert_true(str_contains($html, 'poster="'));
    assert_true(str_contains($html, 'photo.svg'));
    assert_false(str_contains($html, '<iframe'));
    $video['props']['media'] = 'photo.svg';
    assert_true(str_contains((new NodeRenderer(comet_site_test_context()))->render([$video]), '<iframe'));
});

test('the editing canvas shows library videos without autoplay', function (): void {
    comet_site_test_setup();
    file_put_contents(comet_test_workspace_path() . '/media/clip.mp4', "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom");
    $ctx = comet_site_test_context();
    $ctx->preview = true;
    $ctx->annotate = true;
    $html = (new NodeRenderer($ctx))->render([LayoutSchema::node('video', ['media' => 'clip.mp4', 'autoplay' => true])]);
    assert_true(str_contains($html, '<video src="'), $html);
    assert_false(str_contains($html, 'autoplay'));
});

test('library videos play as muted looping backgrounds on the background layer', function (): void {
    comet_site_test_setup();
    file_put_contents(comet_test_workspace_path() . '/media/clip.mp4', "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom");

    $heading = LayoutSchema::node('heading', ['text' => 'Over the video']);
    $column = LayoutSchema::node('column', [], [], [$heading]);
    $style = ['background_image' => 'clip.mp4', 'background_size' => 'contain', 'background_position' => 'top-right', 'background_repeat' => true, 'background_blur' => 6, 'background_effect' => 'parallax', 'overlay' => 'dark'];
    foreach (['section' => [LayoutSchema::node('row', [], [], [$column])], 'row' => [$column], 'column' => [$heading]] as $kind => $children) {
        $node = LayoutSchema::node($kind, [], $style, $children);
        $html = (new NodeRenderer(comet_site_test_context()))->render([$node]);
        assert_same(1, substr_count($html, '<video class="c-background-video"'), $html);
        assert_true(str_contains($html, '<span class="c-background-layer" aria-hidden="true"><video'));
        assert_true(str_contains($html, 'clip.mp4'));
        foreach ([' muted ', ' loop ', ' playsinline ', ' data-background-video>', 'object-fit:contain;object-position:top right'] as $part) {
            assert_true(str_contains($html, $part), $part);
        }
        assert_false(str_contains($html, ' autoplay'), 'The site script starts playback, so reduced motion and canvases stay still.');
        assert_false(str_contains($html, ' controls'));
        foreach (['c-has-bg-video', 'c-has-bg-layer', 'c-bg-effect-parallax', 'c-overlay-dark', '--c-background-blur:6px'] as $part) {
            assert_true(str_contains($html, $part), $part);
        }
        assert_false(str_contains($html, 'c-bg-repeat'), 'Videos never tile.');
        assert_false(str_contains($html, 'background-image'), 'A video is no CSS image.');
    }

    $html = (new NodeRenderer(comet_site_test_context()))->render([LayoutSchema::node('column', [], ['background_image' => 'clip.mp4', 'background_size' => 'original'], [$heading])]);
    assert_true(str_contains($html, 'object-fit:none;object-position:center'));

    (new \CometCMS\Media\MediaRepository())->updateVisibility('clip.mp4', 'private');
    $html = (new NodeRenderer(comet_site_test_context()))->render([LayoutSchema::node('column', [], ['background_image' => 'clip.mp4'], [$heading])]);
    assert_true(str_contains($html, 'Over the video'));
    assert_false(str_contains($html, '<video'), 'Private videos are never shown.');
});

test('pages with background videos load the hashed playback module', function (): void {
    $site = comet_site_test_setup();
    file_put_contents(comet_test_workspace_path() . '/media/clip.mp4', "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom");
    $site->saveLayout('blog', 'index', [LayoutSchema::node('section', [], ['background_image' => 'clip.mp4'], [LayoutSchema::node('row', [], [], [LayoutSchema::node('column', [], [], [LayoutSchema::node('heading', ['text' => 'Over the video'])])])])]);

    $page = (new SiteService(comet_site_test_context($site)))->respond('/blog', []);
    assert_true(str_contains($page['body'], '<script type="module">' . BackgroundVideo::script() . '</script>'), $page['body']);
    assert_true(str_contains(SiteScripts::cspDirective($page['body']), BackgroundVideo::cspSource()));

    $site->saveLayout('blog', 'index', [LayoutSchema::node('section', [], [], [LayoutSchema::node('row', [], [], [LayoutSchema::node('column', [], [], [LayoutSchema::node('heading', ['text' => 'No video'])])])])]);
    $page = (new SiteService(comet_site_test_context($site)))->respond('/blog', []);
    assert_false(str_contains($page['body'], 'initBackgroundVideos'));
});
