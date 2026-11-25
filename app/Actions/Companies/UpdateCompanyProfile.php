<?php

namespace App\Actions\Companies;

use App\Models\Company;
use App\Models\User;
use App\Services\ImageStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The company owner edits what buyers see: name, contact details, about
 * text, logo, and the storefront (banner, accent colour, website and
 * Facebook links).
 */
final class UpdateCompanyProfile
{
    public function __construct(private readonly ImageStore $images) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException when a field is not acceptable
     */
    public function handle(
        User $user,
        Company $company,
        array $data,
        ?UploadedFile $logo = null,
        bool $removeLogo = false,
        ?UploadedFile $banner = null,
        bool $removeBanner = false,
    ): Company {
        Gate::forUser($user)->authorize('manageProfile', $company);

        $validated = Validator::make($data, [
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', 'string', 'max:500'],
            'about' => ['nullable', 'string', 'max:5000'],
            'accent_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'website_url' => ['nullable', 'string', 'max:255', 'url:https,http'],
            'facebook_url' => ['nullable', 'string', 'max:255', 'url:https', 'regex:#^https://(www\.|m\.)?(facebook|fb)\.com/#i'],
        ], [
            'facebook_url.regex' => __('Use a link to a Facebook page, like https://facebook.com/yourcompany.'),
            'accent_color.regex' => __('Use a colour like #2550eb.'),
        ])->validate();

        if (filled($validated['accent_color'] ?? null)) {
            $validated['accent_color'] = strtolower($validated['accent_color']);
        }

        $previous = ['logo_path' => $company->logo_path, 'banner_path' => $company->banner_path];

        $company->logo_path = $this->picture($logo, $removeLogo, $company->logo_path, 'logo', 'logos', 600);

        try {
            $company->banner_path = $this->picture($banner, $removeBanner, $company->banner_path, 'banner', 'banners', 1600);
        } catch (ValidationException $e) {
            if ($logo !== null) {
                $this->images->delete($company->logo_path);
            }

            throw $e;
        }

        $company->fill(array_map(fn (mixed $value) => $value === '' ? null : $value, $validated))->save();

        foreach ($previous as $column => $path) {
            if ($company->{$column} !== $path) {
                $this->images->delete($path);
            }
        }

        return $company;
    }

    private function picture(?UploadedFile $file, bool $remove, ?string $current, string $field, string $directory, int $maxSide): ?string
    {
        if ($file === null) {
            return $remove ? null : $current;
        }

        try {
            return $this->images->store($file, $directory, maxSide: $maxSide);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([$field => __('This file could not be read as a picture. Try a JPG or PNG.')]);
        }
    }
}
