<?php

namespace App\Services\Production;

use App\Enums\ProductionStatus;
use App\Models\ProductionPackage;
use App\Models\Project;
use App\Models\ProjectFile;
use App\Models\SubtitleTrack;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class PackageService
{
    public function __construct(private SrtParser $srt, private SecureArchiveExtractor $extractor) {}

    public function disk(): string
    {
        return config('motionforge.disk', 'local');
    }

    /** Store an uploaded file under the project's private directory. */
    public function storeFile(Project $project, ?User $user, string $role, string $sourcePath, string $originalName, ?string $mime = null): ProjectFile
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) ?: 'bin';
        $rel = $project->storageDir('inputs/'.$role.'/'.Str::uuid().'.'.$ext);
        Storage::disk($this->disk())->put($rel, fopen($sourcePath, 'rb'));

        return ProjectFile::create([
            'project_id' => $project->id, 'user_id' => $user?->id, 'role' => $role, 'disk' => $this->disk(), 'path' => $rel,
            'original_name' => mb_substr(basename($originalName), 0, 200), 'mime' => $mime, 'size' => filesize($sourcePath),
            'sha256' => hash_file('sha256', $sourcePath),
        ]);
    }

    /**
     * Create a new draft package from individually uploaded files.
     * @param array<string, UploadedFile|array<UploadedFile>|null> $files keys: story, narration, srt, voiceover, characters[], notes
     */
    public function createFromUploads(Project $project, User $user, array $files, ?string $notes = null): ProductionPackage
    {
        return DB::transaction(function () use ($project, $user, $files, $notes) {
            $data = ['notes' => $notes];
            foreach (['story' => 'locked_story', 'narration' => 'narration_text', 'srt' => 'srt_text'] as $role => $col) {
                if ($f = $files[$role] ?? null) {
                    $pf = $this->storeFile($project, $user, $role, $f->getRealPath(), $f->getClientOriginalName(), $f->getClientMimeType());
                    $data[$col] = $this->readText($pf->absolutePath());
                }
            }
            if ($f = $files['voiceover'] ?? null) {
                $data['voiceover_file_id'] = $this->storeFile($project, $user, 'voiceover', $f->getRealPath(), $f->getClientOriginalName(), $f->getClientMimeType())->id;
            }
            foreach ((array) ($files['characters'] ?? []) as $img) {
                $this->storeFile($project, $user, 'character_ref', $img->getRealPath(), $img->getClientOriginalName(), $img->getClientMimeType());
            }
            if ($f = $files['notes'] ?? null) {
                $pf = $this->storeFile($project, $user, 'notes', $f->getRealPath(), $f->getClientOriginalName());
                $data['notes'] = trim(($notes ? $notes."\n\n" : '').$this->readText($pf->absolutePath()));
            }

            return $this->newDraft($project, $data);
        });
    }

    /** Create a draft package from a ZIP archive, mapping files to roles by name (filenames may differ). */
    public function createFromArchive(Project $project, User $user, UploadedFile $zip): ProductionPackage
    {
        $tmp = storage_path('app/tmp/extract-'.Str::uuid());
        try {
            $entries = $this->extractor->extract($zip->getRealPath(), $tmp);
            $roles = $this->classify($entries);
            if (! $roles) {
                throw new RuntimeException('No production files were recognised in the archive.');
            }

            return DB::transaction(function () use ($project, $user, $roles) {
                $data = [];
                foreach (['story' => 'locked_story', 'narration' => 'narration_text', 'srt' => 'srt_text', 'notes' => 'notes'] as $role => $col) {
                    if (isset($roles[$role])) {
                        $pf = $this->storeFile($project, $user, $role, $roles[$role]['path'], $roles[$role]['name']);
                        $data[$col] = $this->readText($pf->absolutePath());
                    }
                }
                if (isset($roles['voiceover'])) {
                    $data['voiceover_file_id'] = $this->storeFile($project, $user, 'voiceover', $roles['voiceover']['path'], $roles['voiceover']['name'])->id;
                }
                foreach ($roles['character_ref'] ?? [] as $img) {
                    $this->storeFile($project, $user, 'character_ref', $img['path'], $img['name']);
                }
                if (isset($roles['project_json'])) {
                    $meta = json_decode((string) file_get_contents($roles['project_json']['path']), true);
                    if (is_array($meta)) {
                        $data['notes'] = trim(($data['notes'] ?? '')."\n\nproject.json: ".json_encode(array_intersect_key($meta, array_flip(['title', 'genre', 'duration', 'aspect_ratio', 'role', 'output'])), JSON_UNESCAPED_UNICODE));
                    }
                }

                return $this->newDraft($project, $data);
            });
        } finally {
            File::deleteDirectory($tmp);
        }
    }

    /** @param list<array{name: string, path: string, ext: string}> $entries */
    public function classify(array $entries): array
    {
        $roles = [];
        foreach ($entries as $e) {
            $n = strtolower(basename($e['name']));
            if ($e['ext'] === 'srt') $roles['srt'] ??= $e;
            elseif (in_array($e['ext'], ['mp3', 'wav', 'm4a', 'aac', 'ogg', 'flac'], true)) {
                if (! isset($roles['voiceover']) || str_contains($n, 'voice')) $roles['voiceover'] = $e;
            } elseif (in_array($e['ext'], ['png', 'jpg', 'jpeg', 'webp'], true)) $roles['character_ref'][] = $e;
            elseif ($n === 'project.json') $roles['project_json'] = $e;
            elseif (str_contains($n, 'narration')) $roles['narration'] = $e;
            elseif (str_contains($n, 'locked') || str_contains($n, 'story')) $roles['story'] ??= $e;
            elseif (str_contains($n, 'note')) $roles['notes'] = $e;
        }

        return $roles;
    }

    public function newDraft(Project $project, array $data): ProductionPackage
    {
        $version = (int) $project->packages()->max('version') + 1;
        $package = $project->packages()->create($data + ['version' => $version, 'status' => 'draft', 'source' => $data['source'] ?? 'upload']);
        if ($project->status === ProductionStatus::Draft) {
            $project->update(['status' => ProductionStatus::AwaitingPackage]);
        }

        return $package;
    }

    /** Lock the package: hash every approved input and supersede the previously approved version. */
    public function approve(ProductionPackage $package, User $user, bool $acknowledgeWarnings = false): ProductionPackage
    {
        if ($package->status !== 'valid') {
            throw new RuntimeException('Only a validated package can be approved. Run validation and resolve every failed check first.');
        }
        $warnings = collect($package->validation_report['checks'] ?? [])->where('status', 'warn');
        if ($warnings->isNotEmpty() && ! $acknowledgeWarnings) {
            throw new RuntimeException('This package has warnings. Review them and confirm before approving.');
        }

        return DB::transaction(function () use ($package, $user) {
            $package->project->packages()->where('status', 'approved')->update(['status' => 'superseded']);
            $package->update([
                'status' => 'approved', 'approved_at' => now(), 'approved_by' => $user->id,
                ...$this->hashes($package),
                'audio_duration' => $package->validation_report['audio_duration'] ?? null,
                'srt_end' => $package->validation_report['srt_end'] ?? null,
            ]);
            SubtitleTrack::updateOrCreate(['production_package_id' => $package->id, 'type' => 'source'], [
                'project_id' => $package->project_id, 'cues' => $this->srt->parse($package->srt_text), 'srt_text' => $package->srt_text,
            ]);
            $package->project->update(['status' => ProductionStatus::PackageApproved]);
            $package->project->log('package.approved', "Production package v{$package->version} approved and locked.", ['hashes' => $this->hashes($package)], $user);

            return $package;
        });
    }

    public function hashes(ProductionPackage $package): array
    {
        return [
            'story_hash' => hash('sha256', (string) $package->locked_story),
            'narration_hash' => hash('sha256', (string) $package->narration_text),
            'srt_hash' => hash('sha256', (string) $package->srt_text),
            'voice_hash' => $package->voiceover ? hash_file('sha256', $package->voiceover->absolutePath()) : null,
        ];
    }

    /** Throws when the approved package is missing or any locked input changed since approval. */
    public function assertLocked(Project $project): ProductionPackage
    {
        $package = $project->approvedPackage()->with('voiceover')->first();
        if (! $package || ! $package->isApproved()) {
            throw new LockedNarrationException('Production cannot start: the production package has not been approved.');
        }
        $now = $this->hashes($package);
        foreach ($now as $k => $v) {
            if ($package->{$k} !== $v) {
                throw new LockedNarrationException("The approved package changed after approval ({$k} mismatch). Production is blocked; create and approve a new package version.");
            }
        }

        return $package;
    }

    private function readText(string $path): string
    {
        $text = (string) file_get_contents($path);
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
        if (! mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        }

        return str_replace(["\r\n", "\r"], "\n", $text);
    }
}
