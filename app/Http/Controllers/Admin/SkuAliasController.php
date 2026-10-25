<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SkuAliasRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SkuAliasController extends Controller
{
    public function edit(Request $request): View
    {
        return view('admin.sku-aliases', ['store' => $this->resolveStore($request)]);
    }

    public function update(SkuAliasRequest $request): RedirectResponse
    {
        $store = $this->resolveStore($request);
        $store->update(['sku_aliases' => $request->mappings(), 'sku_aliases_enabled' => $request->boolean('enabled')]);
        activity('operator-actions')->causedBy($request->user())->performedOn($store)->withProperties(['enabled' => $store->sku_aliases_enabled, 'mappings' => $store->sku_aliases])->log('save_sku_aliases');

        return to_route('admin.sku-aliases.edit')->with('status', __('SKU aliases saved.'));
    }
}
