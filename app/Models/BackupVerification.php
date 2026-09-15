<?php

namespace App\Models;

use Database\Factories\BackupVerificationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['path', 'status', 'archive_size', 'checksum', 'entries', 'database_dump', 'error', 'duration_ms', 'verified_at'])]
class BackupVerification extends Model
{
    /** @use HasFactory<BackupVerificationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['verified_at' => 'datetime'];
    }
}
