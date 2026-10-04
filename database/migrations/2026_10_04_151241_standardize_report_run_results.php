<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('report_runs')->whereNotNull('result')->orderBy('id')->chunkById(100, function ($runs): void {
            foreach ($runs as $run) {
                $decoded = gzuncompress((string) base64_decode(Crypt::decryptString($run->result), true));
                if ($decoded === false) {
                    throw new RuntimeException('A stored report result could not be decompressed.');
                }
                if (str_starts_with($decoded, '{')) {
                    continue;
                }

                $legacy = unserialize($decoded, ['allowed_classes' => false]);
                if (! is_object($legacy)) {
                    throw new RuntimeException('A stored report result has an unexpected shape.');
                }
                $fields = (array) $legacy;
                unset($fields['__PHP_Incomplete_Class_Name']);
                $rowsKey = str_starts_with($run->rows_metric, 'count:') ? substr($run->rows_metric, 6) : 'rows';
                $rows = $fields[$rowsKey] ?? [];
                $scanned = str_starts_with($run->scanned_metric, 'count:')
                    ? count($fields[substr($run->scanned_metric, 6)] ?? [])
                    : (int) ($fields[$run->scanned_metric] ?? 0);
                $params = array_intersect_key($fields, array_flip(['startDate', 'endDate', 'threshold', 'minimum', 'minimumEmails', 'days', 'poBoxOnly', 'unfulfilledOnly', 'keywords', 'email']));
                $meta = array_diff_key($fields, $params, array_flip([$rowsKey, 'scanned', 'pages', 'truncated']));
                $result = [
                    'rows' => $rows,
                    'scanned' => $scanned,
                    'pages' => $fields['pages'] ?? $fields['shopifyPages'] ?? $fields['productPages'] ?? 0,
                    'truncated' => ($fields['truncated'] ?? $fields['shopifyTruncated'] ?? $fields['productsTruncated'] ?? false) || ($fields['ordersTruncated'] ?? false),
                    'params' => $params,
                    'meta' => $meta,
                ];
                DB::table('report_runs')->where('id', $run->id)->update([
                    'result' => Crypt::encryptString(base64_encode((string) gzcompress(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)))),
                ]);
            }
        });

        Schema::table('report_runs', function (Blueprint $table): void {
            $table->dropColumn(['scanned_metric', 'rows_metric']);
            $table->string('failure_reason')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('report_runs', function (Blueprint $table): void {
            $table->dropColumn('failure_reason');
            $table->string('scanned_metric')->default('scanned');
            $table->string('rows_metric')->default('count:rows');
        });
    }
};
