<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Database\Factories\CarrierClaimDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Evidence file attached to a carrier claim. The bytes live on the private disk under a generated name; only the sanitised original name is kept for display.
 *
 * @property string $original_name
 * @property string $path
 * @property string $mime_type
 * @property int $size
 */
#[Fillable(['store_id', 'carrier_claim_id', 'uploaded_by', 'original_name', 'path', 'mime_type', 'size'])]
class CarrierClaimDocument extends Model
{
    /** @use HasFactory<CarrierClaimDocumentFactory> */
    use BelongsToStore, HasFactory;

    public const DISK = 'local';

    public const MAX_KILOBYTES = 5120;

    public const MAX_PER_CLAIM = 10;

    /** Extension => content type; the stored extension always comes from here, never from the uploaded name. */
    public const TYPES = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];

    /** Directory holding one claim's files, or every claim's files of a store when no claim is given. */
    public static function directory(int $storeId, ?int $claimId = null): string
    {
        return 'carrier-claims/'.$storeId.($claimId === null ? '' : '/'.$claimId);
    }

    protected static function booted(): void
    {
        static::deleted(function (CarrierClaimDocument $document): void {
            Storage::disk(self::DISK)->delete($document->path);
        });
    }

    /** @return BelongsTo<CarrierClaim, $this> */
    public function claim(): BelongsTo
    {
        return $this->belongsTo(CarrierClaim::class, 'carrier_claim_id');
    }

    /** A display and download name that cannot carry path parts, control characters or an executable extension. */
    public static function safeName(string $name, string $extension): string
    {
        $base = pathinfo(str_replace('\\', '/', $name), PATHINFO_FILENAME);
        $base = trim((string) preg_replace('/[^\p{L}\p{N}_-]+/u', '-', $base), '-_');

        return mb_substr($base !== '' ? $base : 'document', 0, 100).'.'.$extension;
    }
}
