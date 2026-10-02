<?php

class GitHubDiffExtension extends Minz_Extension
{
    private const MAX_FILES = 30;

    private const MAX_BYTES = 200000;

    private const DEFAULT_API_BASE = 'https://api.github.com';

    public string $pat = '';

    public function init()
    {
        parent::init();

        $this->registerHook('entry_before_insert', [$this, 'entryBeforeInsertHook']);
        Minz_View::appendStyle($this->getFileUrl('diff.css', 'css'));

        $this->pat = FreshRSS_Context::userConf()->attributeString('github_diff_pat') ?? '';
    }

    public function handleConfigureAction()
    {
        if (Minz_Request::isPost()) {
            $pat = trim(Minz_Request::paramString('github_diff_pat'));
            // Blank keeps the stored token; the form never echoes it back.
            if ($pat !== '') {
                FreshRSS_Context::userConf()->_attribute('github_diff_pat', $pat);
                FreshRSS_Context::userConf()->save();
                $this->pat = $pat;
            }
        }
    }

    public function entryBeforeInsertHook(FreshRSS_Entry $entry)
    {
        if (!preg_match('#^https://github\.com/([^/]+)/([^/]+)/commit/([0-9a-f]{7,40})$#', $entry->link(), $m)) {
            return $entry;
        }

        $commit = $this->fetchCommit($m[1], $m[2], $m[3]);
        if ($commit === null) {
            return $entry;
        }

        $entry->_content($entry->content(false).$this->render($commit));

        return $entry;
    }

    /** Overridable via env so tests can point at a mock GitHub API. */
    private function apiBase(): string
    {
        $base = getenv('GITHUB_DIFF_API_BASE');

        return rtrim($base !== false && $base !== '' ? $base : self::DEFAULT_API_BASE, '/');
    }

    private function fetchCommit(string $owner, string $repo, string $sha): ?array
    {
        $headers = [
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28',
            'User-Agent: FreshRSS-GitHubDiff',
        ];
        if ($this->pat !== '') {
            $headers[] = 'Authorization: Bearer '.$this->pat;
        }

        $ch = curl_init($this->apiBase()."/repos/$owner/$repo/commits/$sha");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $data = is_string($body) ? json_decode($body, true) : null;
        if ($status !== 200 || !is_array($data)) {
            Minz_Log::warning("GitHubDiff: fetching $owner/$repo@$sha failed (HTTP $status)");

            return null;
        }

        return $data;
    }

    private function render(array $commit): string
    {
        $html = '<div class="ghd"><pre class="ghd-message">'.htmlspecialchars((string) ($commit['commit']['message'] ?? '')).'</pre>';
        $files = $commit['files'] ?? [];
        $size = 0;
        $shown = 0;

        foreach ($files as $file) {
            if ($shown >= self::MAX_FILES || $size >= self::MAX_BYTES) {
                break;
            }
            $shown++;

            $html .= sprintf(
                '<div class="ghd-file">%s <small>(%s, +%d −%d)</small></div>',
                htmlspecialchars((string) ($file['filename'] ?? '')),
                htmlspecialchars((string) ($file['status'] ?? '')),
                (int) ($file['additions'] ?? 0),
                (int) ($file['deletions'] ?? 0),
            );

            if (!isset($file['patch'])) {
                $html .= '<p class="ghd-nodiff">No diff available.</p>';
                continue;
            }

            $html .= '<pre class="ghd-diff">';
            foreach (explode("\n", $file['patch']) as $line) {
                $class = match (true) {
                    str_starts_with($line, '@@') => 'ghd-hunk',
                    str_starts_with($line, '+') => 'ghd-add',
                    str_starts_with($line, '-') => 'ghd-del',
                    default => 'ghd-ctx',
                };
                $html .= '<span class="'.$class.'">'.htmlspecialchars($line).'</span><br>';
            }
            $html .= '</pre>';
            $size = strlen($html);
        }

        if ($shown < count($files)) {
            $html .= sprintf(
                '<p class="ghd-nodiff">… %d more files, <a href="%s">see GitHub</a>.</p>',
                count($files) - $shown,
                htmlspecialchars((string) ($commit['html_url'] ?? '')),
            );
        }

        return $html.'</div>';
    }
}
