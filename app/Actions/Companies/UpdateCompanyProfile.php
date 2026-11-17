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

/** The company owner edits what buyers see: name, contact details, about text and logo. */
final class UpdateCompanyProfile
{
    public function __construct(private readonly ImageStore $images) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException when a field is not acceptable
     */
    public function handle(User $user, Company $company, array $data, ?UploadedFile $logo = null, bool $removeLogo = false): Company
    {
        Gate::forUser($user)->authorize('manageProfile', $company);

        $validated = Validator::make($data, [
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', 'string', 'max:500'],
            'about' => ['nullable', 'string', 'max:5000'],
        ])->validate();

        $previousLogo = $company->logo_path;

        if ($logo !== null) {
            try {
                $company->logo_path = $this->images->store($logo, 'logos', maxSide: 600);
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages(['logo' => __('This file could not be read as a picture. Try a JPG or PNG.')]);
            }
        } elseif ($removeLogo) {
            $company->logo_path = null;
        }

        $company->fill(array_map(fn (mixed $value) => $value === '' ? null : $value, $validated))->save();

        if ($company->logo_path !== $previousLogo) {
            $this->images->delete($previousLogo);
        }

        return $company;
    }
}
