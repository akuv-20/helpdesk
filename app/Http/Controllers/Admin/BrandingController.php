<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Settings\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class BrandingController extends Controller
{
    /** Imágenes de marca que se pueden personalizar. */
    private const ASSETS = ['navbar_logo', 'login_logo', 'login_background', 'favicon'];

    public function __construct(private Settings $settings)
    {
    }

    public function edit(): Response
    {
        return Inertia::render('Admin/Settings/Branding', [
            'values' => collect(self::ASSETS)
                ->mapWithKeys(fn ($k) => [$k => $this->settings->get("brand.$k")])
                ->all(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'navbar_logo' => ['nullable', 'image', 'max:2048'],
            'login_logo' => ['nullable', 'image', 'max:2048'],
            'login_background' => ['nullable', 'image', 'max:5120'],
            'favicon' => ['nullable', 'file', 'mimes:png,ico,svg,jpg,jpeg', 'max:1024'],
            'remove' => ['array'],
            'remove.*' => ['in:navbar_logo,login_logo,login_background,favicon'],
        ]);

        foreach (self::ASSETS as $key) {
            if (in_array($key, $request->input('remove', []), true)) {
                $this->clearAsset($key);
            } elseif ($request->hasFile($key)) {
                $this->storeAsset($request->file($key), $key);
            }
        }

        return back()->with('success', 'Marca actualizada.');
    }

    private function storeAsset(UploadedFile $file, string $key): void
    {
        $dir = public_path('branding');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $this->deleteFile($this->settings->get("brand.$key")); // borra el anterior

        $ext = strtolower($file->getClientOriginalExtension() ?: 'png');
        $name = $key.'_'.Str::random(8).'.'.$ext;
        $file->move($dir, $name);

        $this->settings->setMany(["brand.$key" => '/branding/'.$name]);
    }

    private function clearAsset(string $key): void
    {
        $this->deleteFile($this->settings->get("brand.$key"));
        $this->settings->setMany(["brand.$key" => null]); // null elimina la clave
    }

    private function deleteFile(?string $url): void
    {
        if ($url && str_starts_with($url, '/branding/')) {
            @unlink(public_path(ltrim($url, '/')));
        }
    }
}
