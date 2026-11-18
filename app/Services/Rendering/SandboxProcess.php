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

        return array_merge($env, [
            'PATH' => '/usr/local/bin:/usr/bin:/bin',
            'HOME' => $home,
            // Chrome's IPC socket paths must stay under the 108-char Unix limit: keep TMPDIR short
            'TMPDIR' => sys_get_temp_dir(),
            'LANG' => 'C.UTF-8',
            'NODE_OPTIONS' => '--max-old-space-size=1024',
        ]);
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
