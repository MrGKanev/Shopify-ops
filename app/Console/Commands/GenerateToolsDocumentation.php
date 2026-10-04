<?php

namespace App\Console\Commands;

use App\Application\Reports\ReportRegistry;
use App\Application\Reports\ReportTool;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

#[Signature('docs:tools
    {--path= : Markdown file to update (defaults to docs/tools.md)}
    {--check : Only report whether the generated section is up to date}')]
#[Description('Regenerate the audit tool tables in docs/tools.md from the tool registry (config/reports.php).')]
class GenerateToolsDocumentation extends Command
{
    public const string START_MARKER = '<!-- AUTO-GENERATED:AUDIT-SECTION:START -->';

    public const string END_MARKER = '<!-- AUTO-GENERATED:AUDIT-SECTION:END -->';

    public function handle(ReportRegistry $registry): int
    {
        $path = (string) ($this->option('path') ?: base_path('docs/tools.md'));
        if (! File::exists($path)) {
            $this->error("{$path} does not exist.");

            return self::FAILURE;
        }

        $contents = File::get($path);
        $start = strpos($contents, self::START_MARKER);
        $end = strpos($contents, self::END_MARKER);
        if ($start === false || $end === false || $end < $start) {
            $this->error("{$path} has no AUTO-GENERATED:AUDIT-SECTION markers.");

            return self::FAILURE;
        }

        $generated = substr($contents, 0, $start).self::START_MARKER."\n\n".$this->sections($registry).self::END_MARKER.substr($contents, $end + strlen(self::END_MARKER));

        if ($this->option('check')) {
            if ($generated !== $contents) {
                $this->error("{$path} is out of date. Run php artisan docs:tools.");

                return self::FAILURE;
            }

            $this->info("{$path} is up to date.");

            return self::SUCCESS;
        }

        File::put($path, $generated);
        $this->info("Updated {$path}.");

        return self::SUCCESS;
    }

    private function sections(ReportRegistry $registry): string
    {
        $markdown = '';
        foreach ($registry->bySection() as $section => $tools) {
            $markdown .= "### {$section}\n\n| Page | What it does |\n| --- | --- |\n";
            $markdown .= implode('', array_map(fn (ReportTool $tool): string => '| **'.$this->cell($tool->label).'** | '.$this->cell($tool->description)." |\n", $tools));
            $markdown .= "\n";
        }

        return $markdown;
    }

    private function cell(string $text): string
    {
        return str_replace('|', '\|', $text);
    }
}
