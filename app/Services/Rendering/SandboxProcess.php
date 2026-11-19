<?php

namespace App\Services\Rendering;

use Symfony\Component\Process\Process;

/**
 * Child processes for rendering and media tools. Symfony Process inherits the parent environment
 * (getenv, $_ENV and $_SERVER, which include Laravel's .env secrets) unless each variable is
 * explicitly set to false. This builds a scrubbed environment so no application credential ever
 * reaches the renderer that executes untrusted scene code.
 */
class SandboxProcess
{
    public static function env(string $home): array
    {
        $env = [];
        foreach ([getenv(), $_ENV, $_SERVER] as $source) {
            foreach ((array) $source as $k => $v) {
                if (is_string($k)) {
                    $env[$k] = false;
                }
            }
        }

        if (PHP_OS_FAMILY === 'Windows') {
            return array_merge($env, self::windowsEnv($home));
        }

        return array_merge($env, [
            'PATH' => '/usr/local/bin:/usr/bin:/bin',
            'HOME' => $home,
            // Chrome's IPC socket paths must stay under the 108-char Unix limit: keep TMPDIR short
            'TMPDIR' => sys_get_temp_dir(),
            'LANG' => 'C.UTF-8',
            'NODE_OPTIONS' => '--max-old-space-size=1024',
        ]);
    }

    /**
     * Windows programs (Node, Chrome, FFmpeg) fail to start without the system variables below
     * (e.g. Node aborts during initialisation without SystemRoot). These are machine paths, not
     * secrets; everything else, including Laravel's .env values, stays scrubbed.
     */
    private static function windowsEnv(string $home): array
    {
        $keep = [];
        foreach (['SystemRoot', 'SYSTEMROOT', 'windir', 'SystemDrive', 'ComSpec', 'PATHEXT', 'ProgramFiles', 'ProgramFiles(x86)', 'ProgramW6432', 'CommonProgramFiles', 'ProgramData', 'NUMBER_OF_PROCESSORS', 'PROCESSOR_ARCHITECTURE', 'LOCALAPPDATA', 'APPDATA', 'USERPROFILE'] as $k) {
            $v = getenv($k);
            if (is_string($v) && $v !== '') {
                $keep[$k] = $v;
            }
        }
        $root = $keep['SystemRoot'] ?? $keep['SYSTEMROOT'] ?? 'C:\\Windows';
        $dirs = [$root.'\\System32', $root, $root.'\\System32\\Wbem'];
        // the configured tool binaries may live outside the system directories
        foreach (['node', 'ffmpeg', 'ffprobe', 'chrome'] as $tool) {
            $bin = (string) config('motionforge.'.$tool);
            if ($bin !== '' && (str_contains($bin, '\\') || str_contains($bin, '/'))) {
                $dirs[] = dirname($bin);
            } elseif ($bin !== '' && ($found = (new \Symfony\Component\Process\ExecutableFinder)->find($bin))) {
                $dirs[] = dirname($found);
            }
        }
        $tmp = sys_get_temp_dir();

        return $keep + [
            'PATH' => implode(';', array_values(array_unique($dirs))),
            'HOME' => $home,
            'TEMP' => $tmp, 'TMP' => $tmp, 'TMPDIR' => $tmp,
            'NODE_OPTIONS' => '--max-old-space-size=1024',
        ];
    }

    public static function make(array $command, string $cwd, int $timeout, ?string $input = null): Process
    {
        if (! is_dir($cwd)) {
            mkdir($cwd, 0700, true);
        }
        $p = new Process($command, $cwd, self::env($cwd), $input);
        $p->setTimeout($timeout);

        return $p;
    }
}
