<?php

namespace Tests\Feature\Console\Commands;

use App\Console\Commands\GenerateToolsDocumentation;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class GenerateToolsDocumentationTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = storage_path('framework/testing/tools-'.uniqid().'.md');
        File::ensureDirectoryExists(dirname($this->path));
    }

    protected function tearDown(): void
    {
        File::delete($this->path);

        parent::tearDown();
    }

    public function test_it_rewrites_only_the_section_between_the_markers(): void
    {
        File::put($this->path, "# Tools\n\nIntro\n\n".GenerateToolsDocumentation::START_MARKER."\nstale\n".GenerateToolsDocumentation::END_MARKER."\n\n## Search\n\nKept\n");

        $this->artisan('docs:tools', ['--path' => $this->path])->assertSuccessful();

        $contents = File::get($this->path);
        $this->assertStringStartsWith("# Tools\n\nIntro\n\n".GenerateToolsDocumentation::START_MARKER."\n\n### Core Audit\n\n| Page | What it does |\n| --- | --- |\n| **Saved Reports** |", $contents);
        $this->assertStringEndsWith("\n".GenerateToolsDocumentation::END_MARKER."\n\n## Search\n\nKept\n", $contents);
        $this->assertStringNotContainsString('stale', $contents);
        $this->assertStringContainsString("### Fraud & Compliance\n", $contents);
        $this->assertStringContainsString('| **Chargebacks / Disputes** | Open Shopify Payments disputes needing evidence, sorted by response deadline |', $contents);
        $this->assertStringNotContainsString('Customer LTV', $contents, 'Tools without a navigation section are documented by hand.');

        $this->artisan('docs:tools', ['--path' => $this->path, '--check' => true])->assertSuccessful();
    }

    public function test_check_fails_when_the_generated_section_is_out_of_date(): void
    {
        File::put($this->path, GenerateToolsDocumentation::START_MARKER."\nstale\n".GenerateToolsDocumentation::END_MARKER."\n");

        $this->artisan('docs:tools', ['--path' => $this->path, '--check' => true])->assertFailed();
        $this->assertStringContainsString('stale', File::get($this->path));
    }

    public function test_it_fails_without_markers(): void
    {
        File::put($this->path, "# Tools\n");

        $this->artisan('docs:tools', ['--path' => $this->path])->assertFailed();
        $this->assertSame("# Tools\n", File::get($this->path));
    }

    public function test_committed_tools_documentation_is_up_to_date(): void
    {
        $this->artisan('docs:tools', ['--check' => true])->assertSuccessful();
    }
}
