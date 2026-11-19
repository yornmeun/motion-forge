<?php

namespace App\Services\Production;

use App\Models\ProductionPackage;
use App\Services\Media\Ffmpeg;
use Throwable;

/**
 * Validates an approved-package candidate exactly as the Kikitoons skill requires. It reports
 * problems; it never repairs the story, narration, SRT or audio.
 */
class PackageValidator
{
    public function __construct(private SrtParser $srt, private NarrationMatcher $matcher, private Ffmpeg $ffmpeg, private SceneSheetParser $sheets) {}

    /** @return array{status: string, checks: list<array>, cues: int, audio_duration: ?float, srt_end: ?float, differences: array} */
    public function validate(ProductionPackage $package): array
    {
        $checks = [];
        $add = function (string $key, string $label, string $status, string $message) use (&$checks) {
            $checks[] = compact('key', 'label', 'status', 'message');
        };
        $project = $package->project;

        $story = trim((string) $package->locked_story);
        $add('locked_story', 'Locked story', $story !== '' ? 'pass' : 'fail', $story !== '' ? 'Approved story present ('.str_word_count($story).' words).' : 'The locked story (story_locked.md) is missing.');

        $narration = trim((string) $package->narration_text);
        $add('narration', 'Approved narration', $narration !== '' ? 'pass' : 'fail', $narration !== '' ? count($this->matcher->words($narration)).' spoken words.' : 'narration.txt is missing.');

        $cues = [];
        $srtEnd = null;
        try {
            $cues = $this->srt->parse((string) $package->srt_text);
            $srtEnd = end($cues)['end'];
            $overlaps = 0;
            for ($i = 1; $i < count($cues); $i++) {
                if ($cues[$i]['start'] < $cues[$i - 1]['end'] - 0.05) {
                    $overlaps++;
                }
            }
            $add('srt', 'SRT subtitles', $overlaps ? 'warn' : 'pass', count($cues).' cues, ends at '.gmdate('i:s', (int) $srtEnd).($overlaps ? ", {$overlaps} overlapping cues" : '').'.');
        } catch (Throwable $e) {
            $add('srt', 'SRT subtitles', 'fail', $package->srt_text ? $e->getMessage() : 'story.srt is missing.');
        }

        $audio = null;
        $voice = $package->voiceover;
        if (! $voice) {
            $add('voiceover', 'Voiceover audio', 'fail', 'The voiceover audio file is missing.');
        } else {
            try {
                $audio = $this->ffmpeg->audioDuration($voice->absolutePath());
                $add('voiceover', 'Voiceover audio', $audio > 1 ? 'pass' : 'fail', $audio > 1 ? sprintf('Readable, %s (%.2f s).', gmdate('i:s', (int) $audio), $audio) : 'The audio is empty.');
            } catch (Throwable $e) {
                $add('voiceover', 'Voiceover audio', 'fail', 'The audio file is not readable: '.$e->getMessage());
            }
        }

        if ($audio !== null && $srtEnd !== null) {
            $delta = $audio - $srtEnd;
            if ($srtEnd > $audio + 0.5) {
                $add('timing', 'Audio vs SRT timing', 'fail', sprintf('The SRT ends at %.2f s but the voice ends at %.2f s. Regenerate the SRT or the voice.', $srtEnd, $audio));
            } elseif ($delta > 3) {
                $add('timing', 'Audio vs SRT timing', 'warn', sprintf('The voice runs %.1f s past the last subtitle (%.2f s vs %.2f s). Check for unsubtitled speech.', $delta, $audio, $srtEnd));
            } else {
                $add('timing', 'Audio vs SRT timing', 'pass', sprintf('Voice %.2f s, last subtitle %.2f s.', $audio, $srtEnd));
            }
        }

        $differences = [];
        if ($narration !== '' && $cues) {
            $cmp = $this->matcher->compare($narration, implode(' ', array_column($cues, 'text')));
            $differences = $cmp['differences'];
            if ($cmp['equal']) {
                $add('words', 'SRT matches narration', 'pass', 'Every SRT word matches the approved narration.');
            } elseif ($cmp['similarity'] >= 0.97) {
                $add('words', 'SRT matches narration', 'warn', sprintf('%d small difference(s) (%.1f%% match). Review them below; approving confirms they are intended.', count($differences), $cmp['similarity'] * 100));
            } else {
                $add('words', 'SRT matches narration', 'fail', sprintf('The SRT does not match the approved narration (%.1f%% match). The story will not be changed: correct the SRT or confirm a corrected narration.', $cmp['similarity'] * 100));
            }
        }

        $add('project', 'Title and format', $project->title && $project->aspect_ratio ? 'pass' : 'fail', "“{$project->title}”, {$project->aspect_ratio}, {$project->width}×{$project->height} @ {$project->frame_rate} fps.");

        if ($sheet = $package->document('scene_sheet')) {
            $scenes = $this->sheets->scenes($sheet);
            $lastCue = collect($scenes)->max('cue_end');
            if (! $scenes) {
                $add('scene_sheet', 'Scene sheet', 'fail', 'SCENE_SHEET.md has no scene entries (expected "### SC_001 — Title" blocks).');
            } elseif ($cues && $lastCue !== null && $lastCue < count($cues) - 1) {
                $add('scene_sheet', 'Scene sheet', 'warn', sprintf('%d scenes; they reference SRT cues up to %d of %d. The remaining cues join the last scene.', count($scenes), $lastCue, count($cues)));
            } else {
                $add('scene_sheet', 'Scene sheet', 'pass', sprintf('%d scenes (%s–%s) will be followed as written.', count($scenes), $scenes[0]['id'], end($scenes)['id']));
            }
        }

        $status = collect($checks)->contains('status', 'fail') ? 'invalid' : 'valid';

        return ['status' => $status, 'checks' => $checks, 'cues' => count($cues), 'audio_duration' => $audio, 'srt_end' => $srtEnd, 'differences' => $differences, 'validated_at' => now()->toIso8601String()];
    }
}
