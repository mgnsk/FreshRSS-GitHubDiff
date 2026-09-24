<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require '/var/www/FreshRSS/cli/_cli.php';

FreshRSS_Context::initUser('admin');

final class GitHubDiffExtensionTest extends TestCase
{
    private const WIREMOCK = 'http://wiremock:8080';

    private GitHubDiffExtension $ext;

    protected function setUp(): void
    {
        $ext = Minz_ExtensionManager::findExtension('GitHubDiff');
        $this->assertInstanceOf(GitHubDiffExtension::class, $ext);
        $this->ext = $ext;
        $this->ext->pat = '';
        $this->admin('DELETE', '/requests');
    }

    private function admin(string $method, string $path): string
    {
        $ch = curl_init(self::WIREMOCK.'/__admin'.$path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if (!is_string($body) || $status >= 300) {
            $this->fail("wiremock $method $path failed (HTTP $status): $error $body");
        }

        return $body;
    }

    private function run_hook(string $link, string $content = '<p>orig</p>'): string
    {
        $entry = new FreshRSS_Entry(0, 'guid', 'title', '', $content, $link, 0);
        $result = $this->ext->entryBeforeInsertHook($entry);

        return $result->content(false);
    }

    private function requestCount(): int
    {
        $journal = json_decode($this->admin('GET', '/requests'), true);

        return count($journal['requests'] ?? []);
    }

    public function test_non_commit_link_is_untouched_and_makes_no_request(): void
    {
        $this->assertSame('<p>orig</p>', $this->run_hook('https://github.com/o/r/pull/1'));
        $this->assertSame('<p>orig</p>', $this->run_hook('https://example.com/o/r/commit/aaaaaaa'));
        $this->assertSame(0, $this->requestCount());
    }

    public function test_commit_message_and_diff_are_appended(): void
    {
        $content = $this->run_hook('https://github.com/o/r/commit/aaaaaaa');

        $this->assertStringStartsWith('<p>orig</p><div class="ghd">', $content);
        $this->assertStringContainsString('Fix &lt;b&gt;thing&lt;/b&gt;', $content);
        $this->assertStringContainsString('Long body &amp; details', $content);
        $this->assertStringContainsString('src/a.php <small>(modified, +1 −1)</small>', $content);
        $this->assertStringContainsString('<span class="ghd-hunk">@@ -1,2 +1,2 @@</span>', $content);
        $this->assertStringContainsString('<span class="ghd-del">-old &lt;x&gt;</span>', $content);
        $this->assertStringContainsString('<span class="ghd-add">+new</span>', $content);
        $this->assertStringContainsString('<span class="ghd-ctx"> ctx</span>', $content);
    }

    public function test_file_without_patch_shows_placeholder(): void
    {
        $content = $this->run_hook('https://github.com/o/r/commit/aaaaaaa');

        $this->assertStringContainsString('img.png', $content);
        $this->assertStringContainsString('<p class="ghd-nodiff">No diff available.</p>', $content);
    }

    public function test_file_count_is_capped(): void
    {
        $content = $this->run_hook('https://github.com/o/r/commit/bbbbbbb');

        $this->assertSame(30, substr_count($content, 'class="ghd-file"'));
        $this->assertStringContainsString('… 1 more files, <a href="https://github.com/o/r/commit/bbbbbbb">see GitHub</a>.', $content);
    }

    public function test_byte_size_is_capped(): void
    {
        $content = $this->run_hook('https://github.com/o/r/commit/ccccccc');

        // Each file is ~120KB; the cap (~200KB) is checked between files, so two fit and the third is elided.
        $this->assertSame(2, substr_count($content, 'class="ghd-file"'));
        $this->assertStringContainsString('… 1 more files', $content);
    }

    public function test_api_failure_leaves_entry_untouched(): void
    {
        $this->assertSame('<p>orig</p>', $this->run_hook('https://github.com/o/r/commit/0000000'));
    }

    public function test_pat_is_sent_as_bearer_token_only_when_set(): void
    {
        $this->run_hook('https://github.com/o/r/commit/aaaaaaa');
        $journal = $this->admin('GET', '/requests');
        $this->assertStringContainsString('/repos/o/r/commits/aaaaaaa', $journal);
        $this->assertStringNotContainsString('Bearer', $journal);

        $this->admin('DELETE', '/requests');
        $this->ext->pat = 'secret-token';
        $this->run_hook('https://github.com/o/r/commit/aaaaaaa');
        $this->assertStringContainsString('Bearer secret-token', $this->admin('GET', '/requests'));
    }
}
