<?php

namespace App\Http\Controllers;

use App\Application\Operations\LoadImportFreshness;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ImportFreshnessController extends Controller
{
    public function show(Request $request, LoadImportFreshness $freshness): View
    {
        $store = $this->resolveStore($request);

        return view('operational-issues.import-freshness', $freshness->handle($store, $request->boolean('status')) + ['store' => $store]);
    }

    public function updateGrace(Request $request): RedirectResponse
    {
        $input = $request->validate(['import_grace_hours' => ['required', 'integer', 'between:1,720']]);
        $store = $this->resolveStore($request);
        $store->update(['import_grace_hours' => (int) $input['import_grace_hours']]);
        activity('operator-actions')->causedBy($request->user())->performedOn($store)->withProperties(['import_grace_hours' => $store->import_grace_hours])->log('import_grace_update');

        return to_route('operational-issues.import-freshness')->with('status', __('Grace window saved.'));
    }
}
