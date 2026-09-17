<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateOperationalIssueRequest;
use App\Models\Store;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OperationalIssueController extends Controller
{
    public function index(Request $request): View
    {
        $store = $this->store($request);
        $status = $request->string('status')->toString();
        $issues = $store->operationalIssues()
            ->with('owner:id,name')
            ->when(in_array($status, ['open', 'in_progress', 'resolved', 'ignored'], true), fn ($query) => $query->where('status', $status))
            ->orderByRaw("case priority when 'urgent' then 1 when 'high' then 2 when 'normal' then 3 else 4 end")
            ->latest('last_seen_at')
            ->paginate(50)
            ->withQueryString();

        return view('operational-issues.index', [
            'issues' => $issues,
            'status' => $status,
            'owners' => $store->users()->orderBy('name')->get(['users.id', 'users.name']),
            'openCount' => $store->operationalIssues()->whereIn('status', ['open', 'in_progress'])->count(),
        ]);
    }

    public function update(UpdateOperationalIssueRequest $request, int $issue): RedirectResponse
    {
        $store = $this->store($request);
        $operationalIssue = $store->operationalIssues()->findOrFail($issue);
        $validated = $request->validated();
        $ownerId = $validated['owner_user_id'] ?? null;

        if ($ownerId !== null && ! $store->users()->whereKey($ownerId)->exists()) {
            abort(404);
        }

        $operationalIssue->update([
            ...$validated,
            'owner_user_id' => $ownerId,
            'resolved_at' => $validated['status'] === 'resolved' ? now() : null,
        ]);
        activity('operator-actions')->causedBy($request->user())->performedOn($operationalIssue)
            ->withProperties(['status' => $validated['status'], 'priority' => $validated['priority']])
            ->log('update_operational_issue');

        return back()->with('status', 'Issue updated.');
    }

    private function store(Request $request): Store
    {

        return $this->resolveStore($request);
    }
}
