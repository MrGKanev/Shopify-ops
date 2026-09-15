<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\HealthIncident;
use Illuminate\Http\Request;
use Illuminate\View\View;

class HealthIncidentController extends Controller
{
    public function __invoke(Request $request): View
    {
        $validated = $request->validate([
            'status' => ['nullable', 'in:open,resolved'],
            'component' => ['nullable', 'string', 'max:100'],
        ]);
        $status = (string) ($validated['status'] ?? '');
        $selectedComponent = trim((string) ($validated['component'] ?? ''));
        $query = HealthIncident::query()
            ->when($status === 'open', fn ($builder) => $builder->whereNull('resolved_at'))
            ->when($status === 'resolved', fn ($builder) => $builder->whereNotNull('resolved_at'))
            ->when($selectedComponent !== '', fn ($builder) => $builder->where('check_name', $selectedComponent))
            ->orderByDesc('started_at')
            ->orderByDesc('id');
        $recentResolved = HealthIncident::query()
            ->whereNotNull('resolved_at')
            ->where('resolved_at', '>=', now()->subDays(30))
            ->latest('resolved_at')
            ->limit(1000)
            ->get(['started_at', 'resolved_at']);
        $averageRecoverySeconds = $recentResolved->isEmpty()
            ? null
            : (int) round($recentResolved->average(fn (HealthIncident $incident): int => $incident->started_at->diffInSeconds($incident->resolved_at)));

        return view('admin.health-incidents', [
            'incidents' => $query->paginate(50)->withQueryString(),
            'status' => $status,
            'selectedComponent' => $selectedComponent,
            'components' => HealthIncident::query()->orderBy('check_label')->pluck('check_label', 'check_name'),
            'openCount' => HealthIncident::query()->whereNull('resolved_at')->count(),
            'resolvedLast30Days' => $recentResolved->count(),
            'averageRecoverySeconds' => $averageRecoverySeconds,
        ]);
    }
}
