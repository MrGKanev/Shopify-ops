<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

class FrozenClockTest extends TestCase
{
    public function test_every_test_starts_on_the_frozen_instant(): void
    {
        $this->assertTrue(Carbon::hasTestNow());
        $this->assertTrue(CarbonImmutable::hasTestNow());
        $this->assertSame(CarbonImmutable::parse(self::FROZEN_NOW)->getTimestamp(), now()->getTimestamp());
    }

    public function test_no_test_can_bypass_the_frozen_clock(): void
    {
        $offenders = [];
        foreach ($this->sourceFiles() as $path => $source) {
            if (! preg_match('/^class \w+ extends (\w+)/m', $source, $class)) {
                continue;
            }
            if ($class[1] !== 'TestCase') {
                $offenders[] = "{$path}: extends {$class[1]} instead of Tests\\TestCase";
            }
            if (preg_match('/function setUp\(\)[^{]*\{(?:(?!\n    \}).)*/s', $source, $setUp) && ! str_contains($setUp[0], 'parent::setUp()')) {
                $offenders[] = "{$path}: setUp() does not call parent::setUp()";
            }
            if (preg_match('/setTestNow\(\s*(null)?\s*\)/', $source)) {
                $offenders[] = "{$path}: clears or sets the test clock directly; use travelTo()";
            }
            $restored = strrpos($source, 'travelBack()');
            if ($restored !== false && ! str_contains(substr($source, $restored), 'travelTo(')) {
                $offenders[] = "{$path}: travelBack() leaves the clock running; follow it with travelTo()";
            }
        }

        $this->assertSame([], $offenders);
    }

    /** @return array<string, string> */
    private function sourceFiles(): array
    {
        $root = dirname(__DIR__);
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if (str_ends_with($file->getFilename(), 'Test.php') && $file->getFilename() !== 'FrozenClockTest.php') {
                $files[substr($file->getPathname(), strlen($root) + 1)] = (string) file_get_contents($file->getPathname());
            }
        }

        return $files;
    }
}
