<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class BulgarianTranslationsTest extends TestCase
{
    public function test_every_static_english_translation_key_has_a_bulgarian_entry(): void
    {
        $bulgarian = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/lang/bg.json'), true, flags: JSON_THROW_ON_ERROR);
        $missing = [];

        foreach (['app', 'resources/views'] as $directory) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2).'/'.$directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                preg_match_all('/(?<![\w>$])__\(\s*(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)")/', (string) file_get_contents($file->getPathname()), $matches);
                foreach (array_merge($matches[1], $matches[2]) as $key) {
                    $key = str_replace("\\'", "'", $key);
                    // Skip placeholder-only, interpolated, dotted file keys and keys already written in Bulgarian.
                    if (preg_match('/[A-Za-z]/', preg_replace('/:\w+/', '', $key)) && ! str_contains($key, '$') && ! preg_match('/^[\w.]+$/', $key) && ! preg_match('/\p{Cyrillic}/u', $key) && ! isset($bulgarian[$key])) {
                        $missing[$key] = true;
                    }
                }
            }
        }

        $this->assertSame([], array_keys($missing), 'Add these keys to lang/bg.json.');
    }
}
