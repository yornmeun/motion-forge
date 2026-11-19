<?php

namespace Tests\Unit\Services\Production;

use App\Services\Production\SrtParser;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class SrtParserTest extends TestCase
{
    public function test_reads_standard_srt_with_multi_line_cues(): void
    {
        $cues = (new SrtParser)->parse("1\n00:00:00,000 --> 00:00:02,500\nFirst line\nsecond line\n\n2\n00:00:02,500 --> 00:00:04,000\nNext cue\n");

        $this->assertSame(['First line second line', 'Next cue'], array_column($cues, 'text'));
        $this->assertSame([0.0, 2.5], array_column($cues, 'start'));
    }

    public function test_reads_srt_without_blank_lines_and_ignores_a_note_before_the_first_cue(): void
    {
        $srt = "PROVISIONAL — CLAUDE MUST RETIME TO AUDIO\n1\n00:00:00,250 --> 00:00:02,135\nThe worst thing about being betrayed\n2\n00:00:02,135 --> 00:00:04,076\nisn't discovering that someone hates you.\n";

        $cues = (new SrtParser)->parse($srt);

        $this->assertSame(['The worst thing about being betrayed', "isn't discovering that someone hates you."], array_column($cues, 'text'));
        $this->assertSame([0.25, 2.135], array_column($cues, 'start'));
    }

    public function test_keeps_a_number_that_is_spoken_text(): void
    {
        $cues = (new SrtParser)->parse("1\n00:00:00,000 --> 00:00:01,000\nThe year was\n1999\n2\n00:00:01,000 --> 00:00:02,000\nand it rained.\n");

        $this->assertSame(['The year was 1999', 'and it rained.'], array_column($cues, 'text'));
    }

    public function test_rejects_a_file_without_timing_lines(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new SrtParser)->parse("Just some notes\nwithout any cues\n");
    }
}
