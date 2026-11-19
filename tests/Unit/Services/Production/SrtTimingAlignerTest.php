<?php

namespace Tests\Unit\Services\Production;

use App\Services\Media\Ffmpeg;
use App\Services\Production\SrtParser;
use App\Services\Production\SrtTimingAligner;
use PHPUnit\Framework\TestCase;

class SrtTimingAlignerTest extends TestCase
{
    public function test_retimes_invalid_cues_to_speech_spans_without_changing_words(): void
    {
        $parser = new SrtParser;
        $aligner = new SrtTimingAligner($parser, new Ffmpeg);
        $cues = $parser->parseForAlignment("1\n00:00:03,000 --> 00:00:02,000\nFirst cue\n\n2\n00:00:03,000 --> 00:00:02,000\nLast cue\n");

        $aligned = $aligner->align($cues, 4.0, [['start' => 1.0, 'end' => 1.5]]);

        $this->assertSame(['First cue', 'Last cue'], array_column($aligned, 'text'));
        $this->assertSame(0.0, $aligned[0]['start']);
        $this->assertSame($aligned[0]['end'], $aligned[1]['start']);
        $this->assertSame(4.0, $aligned[1]['end']);
        $this->assertSame(['First cue', 'Last cue'], array_column($parser->parse($parser->format($aligned)), 'text'));
    }

    public function test_requests_alignment_for_cues_that_do_not_fit_the_voiceover(): void
    {
        $aligner = new SrtTimingAligner(new SrtParser, new Ffmpeg);

        $this->assertTrue($aligner->requiresAlignment([
            ['index' => 0, 'start' => 164.438, 'end' => 164.338, 'text' => 'Did you find the recordings?'],
        ], 164.49));
    }
}
