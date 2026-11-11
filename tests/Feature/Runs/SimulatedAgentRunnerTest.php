<?php

namespace Tests\Feature\Runs;

use App\Runner\Agent\AgentInvocation;
use App\Runner\Agent\AgentRunner;
use App\Runner\Agent\SimulatedAgentRunner;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Tests\TestCase;

class SimulatedAgentRunnerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = storage_path('framework/testing/simulated');
        File::ensureDirectoryExists($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    private function invoke(string $prompt, ?string $resume = null)
    {
        return (new SimulatedAgentRunner)->run(new AgentInvocation(
            workingDirectory: $this->dir,
            prompt: $prompt,
            model: 'x',
            effort: 'low',
            apiKey: 'unused',
            configDirectory: $this->dir,
            timeoutSeconds: 10,
            resumeSessionId: $resume,
            environment: ['SPECIFY_FEATURE_DIRECTORY' => 'specs/001-demo'],
        ), fn () => null, fn () => false);
    }

    public function test_it_writes_placeholder_spec_kit_files()
    {
        $this->assertTrue($this->invoke('/speckit-specify Demo feature')->successful);
        $this->assertFileExists("{$this->dir}/specs/001-demo/spec.md");

        $this->invoke('/speckit-plan');
        $this->invoke('/speckit-tasks');
        $this->assertFileExists("{$this->dir}/specs/001-demo/plan.md");

        $this->invoke('/speckit-implement');
        $this->assertStringContainsString('- [x] T001', (string) File::get("{$this->dir}/specs/001-demo/tasks.md"));

        $reply = $this->invoke('Only signed-in people', 'session-1');
        $this->assertSame('session-1', $reply->sessionId);
        $this->assertStringContainsString('Only signed-in people', (string) File::get("{$this->dir}/specs/001-demo/spec.md"));
    }

    public function test_it_is_refused_in_production()
    {
        config(['factory.runner.driver' => 'simulated']);
        $this->assertInstanceOf(SimulatedAgentRunner::class, app(AgentRunner::class));

        $this->app['env'] = 'production';

        $this->expectException(InvalidArgumentException::class);
        app(AgentRunner::class);
    }
}
