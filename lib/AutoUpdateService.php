<?php
/**
 * DI PARMA | Auto Update — git fast-forward pull on this node.
 * Never touches .env, logs, cache, tmp, uploads, or backups.
 */

final class AutoUpdateService
{
    private const FLAG_FILE = 'auto_update.json';
    private const LOCK_FILE = 'auto_update.lock';
    private const LOG_FILE  = 'auto_update.log';
    private const ALLOWED_BRANCHES = ['main', 'master'];

    public static function root(): string
    {
        return defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__);
    }

    public static function cacheDir(): string
    {
        $dir = defined('CACHE_PATH') ? CACHE_PATH : self::root() . '/cache';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }

    public static function secret(): string
    {
        foreach (['AUTO_UPDATE_SECRET', 'PEER_SYNC_SECRET', 'WEBHOOK_HMAC_SECRET'] as $key) {
            $val = trim((string) (function_exists('env') ? env($key, '') : (getenv($key) ?: '')));
            if ($val !== '') {
                return $val;
            }
        }
        return '';
    }

    public static function branch(): string
    {
        $branch = strtolower(trim((string) (function_exists('env') ? env('AUTO_UPDATE_BRANCH', 'main') : 'main')));
        return in_array($branch, self::ALLOWED_BRANCHES, true) ? $branch : 'main';
    }

    public static function enabled(): bool
    {
        $state = self::readState();
        if (array_key_exists('enabled', $state)) {
            return (bool) $state['enabled'];
        }
        $env = function_exists('env') ? env('AUTO_UPDATE_ENABLED', null) : null;
        if ($env === null || $env === '') {
            return defined('APP_IS_PROD') && APP_IS_PROD;
        }
        return (bool) $env;
    }

    public static function setEnabled(bool $on): void
    {
        $state = self::readState();
        $state['enabled'] = $on;
        $state['toggled_at'] = date('c');
        self::writeState($state);
    }

    public static function status(bool $fetchRemote = false): array
    {
        $git = self::gitBinary();
        $root = self::root();
        $isRepo = is_dir($root . '/.git');
        $head = $isRepo ? trim(self::run([$git, '-C', $root, 'rev-parse', 'HEAD'])['out']) : '';
        $short = $isRepo ? trim(self::run([$git, '-C', $root, 'rev-parse', '--short', 'HEAD'])['out']) : '';
        $currentBranch = $isRepo ? trim(self::run([$git, '-C', $root, 'rev-parse', '--abbrev-ref', 'HEAD'])['out']) : '';
        $subject = $isRepo ? trim(self::run([$git, '-C', $root, 'log', '-1', '--format=%s'])['out']) : '';
        $when = $isRepo ? trim(self::run([$git, '-C', $root, 'log', '-1', '--format=%ci'])['out']) : '';
        $dirty = $isRepo ? trim(self::run([$git, '-C', $root, 'status', '--porcelain'])['out']) : '';
        $remoteSha = '';
        $behind = null;
        if ($fetchRemote && $isRepo) {
            self::run([$git, '-C', $root, 'fetch', '--quiet', 'origin', self::branch()], 60);
            $remoteSha = trim(self::run([$git, '-C', $root, 'rev-parse', 'origin/' . self::branch()])['out']);
            if ($head !== '' && $remoteSha !== '' && preg_match('/^[0-9a-f]{7,40}$/i', $head . $remoteSha)) {
                $count = trim(self::run([$git, '-C', $root, 'rev-list', '--count', $head . '..origin/' . self::branch()])['out']);
                $behind = ctype_digit($count) ? (int) $count : null;
            }
        }

        $state = self::readState();
        return [
            'success'       => true,
            'enabled'       => self::enabled(),
            'secret_set'    => self::secret() !== '',
            'role'          => defined('APP_IS_LOCAL') && APP_IS_LOCAL ? 'local' : 'remote',
            'site'          => defined('SITE_URL') ? SITE_URL : '',
            'git_available' => $git !== '',
            'is_repo'       => $isRepo,
            'branch'        => $currentBranch,
            'target_branch' => self::branch(),
            'sha'           => $head,
            'short_sha'     => $short,
            'subject'       => $subject,
            'committed_at'  => $when,
            'dirty'         => $dirty !== '',
            'dirty_count'   => $dirty === '' ? 0 : count(preg_split('/\R/', $dirty) ?: []),
            'remote_sha'    => $remoteSha,
            'behind'        => $behind,
            'last_run'      => $state['last_run'] ?? null,
            'last_result'   => $state['last_result'] ?? null,
            'webhook'       => rtrim((string) (defined('SITE_URL') ? SITE_URL : ''), '/') . '/api/auto_update.php',
        ];
    }

    public static function pull(string $reason = 'manual', bool $force = false): array
    {
        if (!self::enabled()) {
            return ['success' => false, 'message' => 'Auto Update is disabled'];
        }

        $git = self::gitBinary();
        if ($git === '') {
            return ['success' => false, 'message' => 'git is not available on this server'];
        }

        $root = self::root();
        if (!is_dir($root . '/.git')) {
            return ['success' => false, 'message' => 'This directory is not a git repository'];
        }

        $lockPath = self::cacheDir() . '/' . self::LOCK_FILE;
        $lock = fopen($lockPath, 'c+');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            return ['success' => false, 'message' => 'An update is already running'];
        }

        $started = microtime(true);
        try {
            $before = trim(self::run([$git, '-C', $root, 'rev-parse', '--short', 'HEAD'])['out']);
            $dirty = trim(self::run([$git, '-C', $root, 'status', '--porcelain', '--untracked-files=no'])['out']);
            if ($dirty !== '' && !$force) {
                return [
                    'success' => false,
                    'message' => 'Working tree has local changes; refuse to overwrite. Use force from admin if required.',
                    'dirty'   => true,
                    'before'  => $before,
                ];
            }

            $fetch = self::run([$git, '-C', $root, 'fetch', 'origin', self::branch()], 90);
            if ($fetch['code'] !== 0) {
                return ['success' => false, 'message' => 'git fetch failed: ' . $fetch['err'], 'before' => $before];
            }

            $merge = self::run([
                $git, '-C', $root, 'merge', '--ff-only', 'origin/' . self::branch(),
            ], 90);
            if ($merge['code'] !== 0) {
                return [
                    'success' => false,
                    'message' => 'git merge --ff-only failed: ' . ($merge['err'] ?: $merge['out']),
                    'before'  => $before,
                ];
            }

            $after = trim(self::run([$git, '-C', $root, 'rev-parse', '--short', 'HEAD'])['out']);
            $subject = trim(self::run([$git, '-C', $root, 'log', '-1', '--format=%s'])['out']);
            self::reloadRuntime();

            $result = [
                'success'  => true,
                'message'  => $before === $after ? 'Already up to date' : 'Updated ' . $before . ' → ' . $after,
                'before'   => $before,
                'after'    => $after,
                'changed'  => $before !== $after,
                'subject'  => $subject,
                'reason'   => $reason,
                'ms'       => (int) round((microtime(true) - $started) * 1000),
            ];
            self::rememberRun($result);
            self::log(($result['changed'] ? 'UPDATED' : 'NOOP') . ' ' . $result['message'] . ' via ' . $reason);
            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public static function verifyGithubSignature(string $rawBody): bool
    {
        $secret = self::secret();
        if ($secret === '') {
            return false;
        }
        $sig = (string) ($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '');
        if ($sig === '' || !str_starts_with($sig, 'sha256=')) {
            return false;
        }
        $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);
        return hash_equals($expected, $sig);
    }

    public static function verifyToken(): bool
    {
        $secret = self::secret();
        if ($secret === '') {
            return false;
        }
        $given = (string) ($_SERVER['HTTP_X_AUTO_UPDATE_TOKEN'] ?? $_SERVER['HTTP_X_DIPARMA_UPDATE'] ?? '');
        return $given !== '' && hash_equals($secret, $given);
    }

    public static function allowedRef(?string $ref): bool
    {
        if ($ref === null || $ref === '') {
            return true;
        }
        $branch = self::branch();
        return $ref === 'refs/heads/' . $branch || $ref === $branch;
    }

    private static function rememberRun(array $result): void
    {
        $state = self::readState();
        $state['last_run'] = date('c');
        $state['last_result'] = $result;
        self::writeState($state);
    }

    private static function readState(): array
    {
        $path = self::cacheDir() . '/' . self::FLAG_FILE;
        if (!is_file($path)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }

    private static function writeState(array $state): void
    {
        $path = self::cacheDir() . '/' . self::FLAG_FILE;
        @file_put_contents($path, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    }

    private static function log(string $line): void
    {
        $dir = defined('LOGS_PATH') ? LOGS_PATH : self::root() . '/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents($dir . '/' . self::LOG_FILE, '[' . date('Y-m-d H:i:s') . '] ' . $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    private static function gitBinary(): string
    {
        static $bin = null;
        if ($bin !== null) {
            return $bin;
        }
        $candidates = ['git'];
        if (DIRECTORY_SEPARATOR === '\\') {
            $candidates[] = 'C:\\Program Files\\Git\\cmd\\git.exe';
            $candidates[] = 'C:\\Program Files\\Git\\bin\\git.exe';
        }
        foreach ($candidates as $candidate) {
            $probe = self::run([$candidate, '--version'], 8);
            if ($probe['code'] === 0) {
                $bin = $candidate;
                return $bin;
            }
        }
        $bin = '';
        return $bin;
    }

    private static function reloadRuntime(): void
    {
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
        foreach ([
            ['systemctl', 'reload', 'php8.3-fpm'],
            ['systemctl', 'reload', 'php8.2-fpm'],
            ['systemctl', 'reload', 'php-fpm'],
        ] as $cmd) {
            $r = self::run($cmd, 8);
            if ($r['code'] === 0) {
                break;
            }
        }
    }

    /**
     * @param list<string> $cmd
     * @return array{code:int,out:string,err:string}
     */
    private static function run(array $cmd, int $timeout = 30): array
    {
        if ($cmd === [] || !function_exists('proc_open')) {
            return ['code' => 127, 'out' => '', 'err' => 'proc_open unavailable'];
        }
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = @proc_open($cmd, $descriptors, $pipes, self::root(), null, ['bypass_shell' => true]);
        if (!is_resource($proc)) {
            return ['code' => 127, 'out' => '', 'err' => 'failed to start ' . ($cmd[0] ?? '')];
        }
        fclose($pipes[0]);
        stream_set_timeout($pipes[1], $timeout);
        stream_set_timeout($pipes[2], $timeout);
        $out = stream_get_contents($pipes[1]) ?: '';
        $err = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        return ['code' => (int) $code, 'out' => trim($out), 'err' => trim($err)];
    }
}
