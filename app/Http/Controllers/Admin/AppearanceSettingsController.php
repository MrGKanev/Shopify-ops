<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AppearanceSettingsRequest;
use App\Models\AppSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class AppearanceSettingsController extends Controller
{
    public function update(AppearanceSettingsRequest $request): RedirectResponse
    {
        $settings = AppSetting::query()->firstOrCreate(['id' => 1], ['site_name' => config('app.name')]);
        $attributes = $request->safe()->only(['site_name', 'custom_links']);
        $replacedImages = [];

        $this->applyImage($request, $attributes, $replacedImages, $settings, 'logo', 'logo_path', 'remove_logo');
        $this->applyImage($request, $attributes, $replacedImages, $settings, 'login_image', 'login_image_path', 'remove_login_image');

        $settings->update($attributes);
        Storage::disk('public')->delete($replacedImages);

        activity('administration')->causedBy($request->user())->performedOn($settings)->log('Appearance settings updated');

        return back()->with('status', 'Appearance settings updated.');
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $replacedImages
     */
    private function applyImage(AppearanceSettingsRequest $request, array &$attributes, array &$replacedImages, AppSetting $settings, string $input, string $column, string $removeInput): void
    {
        $file = $request->file($input);
        if ($file instanceof UploadedFile) {
            $path = $file->store('branding', 'public');
            abort_if($path === false, 500, 'The image could not be stored.');
            $attributes[$column] = $path;
        } elseif ($request->boolean($removeInput)) {
            $attributes[$column] = null;
        } else {
            return;
        }

        $oldPath = $settings->{$column};
        if (filled($oldPath) && $oldPath !== $attributes[$column]) {
            $replacedImages[] = $oldPath;
        }
    }
}
