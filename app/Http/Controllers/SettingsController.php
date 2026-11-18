<?php

namespace App\Http\Controllers;

use App\Services\Media\Ffmpeg;
use App\Services\Rendering\KikitoonsEngine;
use App\Services\Rendering\SandboxProcess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function profile(Request $request): View
    {
        return view('settings.profile', ['user' => $request->user()]);
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $d = $request->validate(['name' => ['required', 'string', 'max:120'], 'email' => ['required', 'email', 'max:190', Rule::unique('users')->ignore($request->user()->id)]]);
        $request->user()->update($d);

        return back()->with('status', 'Profile saved.');
    }

    public function security(Request $request): View
    {
        return view('settings.security', ['sessions' => \DB::table('sessions')->where('user_id', $request->user()->id)->orderByDesc('last_activity')->get()]);
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $d = $request->validate(['current_password' => ['required', 'current_password'], 'password' => ['required', 'confirmed', Password::min(12)]]);
        $request->user()->update(['password' => $d['password']]);
        \DB::table('sessions')->where('user_id', $request->user()->id)->where('id', '!=', $request->session()->getId())->delete();

        return back()->with('status', 'Password changed; other sessions were signed out.');
    }

    /** Toolchain status: every check runs a real command. */
    public function rendering(Ffmpeg $ffmpeg, KikitoonsEngine $engine): View
    {
        $run = function (array $cmd) {
            try {
                $p = SandboxProcess::make($cmd, storage_path('app/tmp/render'), 20);
                $p->run();

                return $p->isSuccessful() ? trim(strtok($p->getOutput().$p->getErrorOutput(), "\n")) : null;
            } catch (\Throwable) {
                return null;
            }
        };
        @mkdir(storage_path('app/tmp/render'), 0700, true);
        $info = $engine->engineInfo();

        return view('settings.rendering', ['checks' => [
            ['Node.js', $run([config('motionforge.node'), '--version'])],
            ['FFmpeg', $run([config('motionforge.ffmpeg'), '-version'])],
            ['FFprobe', $run([config('motionforge.ffprobe'), '-version'])],
            ['Chrome (headless renderer)', $run([config('motionforge.chrome'), '--version'])],
            ['Playwright core', is_file(base_path('node_modules/playwright-core/package.json')) ? 'playwright-core '.json_decode(file_get_contents(base_path('node_modules/playwright-core/package.json')), true)['version'] : null],
            ['Poppins Bold font', is_file(config('motionforge.font')) ? basename(config('motionforge.font')) : null],
        ], 'engine' => $info, 'queue' => config('queue.default'), 'workers' => config('motionforge.render_workers'), 'originalConfigured' => (bool) config('motionforge.engine_path')]);
    }
}
