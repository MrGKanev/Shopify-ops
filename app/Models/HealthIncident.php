<?php

namespace App\Models;

use Carbon\CarbonInterval;
use Database\Factories\HealthIncidentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['check_name', 'check_label', 'severity', 'summary', 'observations', 'started_at', 'last_observed_at', 'resolved_at'])]
class HealthIncident extends Model
{
    /** @use HasFactory<HealthIncidentFactory> */
    use HasFactory;

    public function durationInSeconds(): int
    {
        return (int) $this->started_at->diffInSeconds($this->resolved_at ?? now());
    }

    public function durationForHumans(): string
    {
        return CarbonInterval::seconds($this->durationInSeconds())->cascade()->forHumans(['short' => true, 'parts' => 2]);
    }

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'last_observed_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }
}
