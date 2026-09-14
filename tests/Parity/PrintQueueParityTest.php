<?php

declare(strict_types=1);

use App\Http\Requests\PrintQueueRequest;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2).'/src/JsonFileLock.php';
require_once dirname(__DIR__, 2).'/src/PrintQueue.php';

final class PrintQueueParityTest extends TestCase
{
    private string $dataDir;

    protected function setUp(): void
    {
        $this->dataDir = sys_get_temp_dir().'/print-queue-parity-'.bin2hex(random_bytes(6));
        mkdir($this->dataDir);
        PrintQueue::setDataDir($this->dataDir);
    }

    protected function tearDown(): void
    {
        @unlink($this->dataDir.'/print_queue.json');
        @unlink($this->dataDir.'/print_queue.json.lock');
        @rmdir($this->dataDir);
    }

    public function test_plain_order_number_normalization_matches(): void
    {
        PrintQueue::add('  ORD-001  ');

        $this->assertSame(PrintQueue::all()[0]['order_number'], $this->laravelOrderNumber('  ORD-001  '));
    }

    public function test_leading_hash_is_an_explicit_laravel_difference(): void
    {
        PrintQueue::add(' #ORD-002 ');

        $this->assertSame('#ORD-002', PrintQueue::all()[0]['order_number']);
        $this->assertSame('ORD-002', $this->laravelOrderNumber(' #ORD-002 '));
    }

    private function laravelOrderNumber(string $value): string
    {
        $request = PrintQueueRequest::create('/print-queue', 'POST', ['order_number' => $value]);
        $prepare = new ReflectionMethod($request, 'prepareForValidation');
        $prepare->invoke($request);

        return (string) $request->input('order_number');
    }
}
