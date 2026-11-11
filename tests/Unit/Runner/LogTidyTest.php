<?php

namespace Tests\Unit\Runner;

use App\Runner\RunRecorder;
use App\Runs\Pipelines\SetupSpecKit;
use PHPUnit\Framework\TestCase;

class LogTidyTest extends TestCase
{
    public function test_box_borders_are_removed_without_breaking_other_characters()
    {
        $this->assertSame('✓ Check required tools (ok)', SetupSpecKit::tidy('│ ✓ Check required tools (ok)        │'));
        $this->assertSame('└── ● Finalize (project ready)', SetupSpecKit::tidy('│ └── ● Finalize (project ready) │'));
        $this->assertSame('Project ready.', SetupSpecKit::tidy("\e[32mProject ready.\e[0m"));
    }

    public function test_banner_and_empty_border_lines_are_dropped()
    {
        $this->assertNull(SetupSpecKit::tidy('███████╗██████╗ ███████╗'));
        $this->assertNull(SetupSpecKit::tidy('╚══════╝╚═╝     ╚══════╝ ╚═════╝╚═╝╚═╝        ╚═╝'));
        $this->assertNull(SetupSpecKit::tidy('╭──────────────────────╮'));
        $this->assertNull(SetupSpecKit::tidy('│                      │'));
    }

    public function test_broken_byte_sequences_are_repaired()
    {
        $broken = "ok \xE2\x9C done";

        $this->assertFalse(mb_check_encoding($broken, 'UTF-8'));
        $this->assertTrue(mb_check_encoding(RunRecorder::validUtf8($broken), 'UTF-8'));
        $this->assertSame('plain ✓', RunRecorder::validUtf8('plain ✓'));
    }
}
