<?php

namespace App\Filament\Seller\Pages;

use App\Actions\Companies\UpdateCompanyProfile;
use App\Filament\Seller\Pages\Concerns\ForCompany;
use BackedEnum;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * What buyers see about the company: name, contact details, about text and
 * logo. The logo is not stored by the form: the action re-encodes it
 * through ImageStore like every other picture.
 *
 * @property-read Schema $form
 */
class CompanyProfile extends Page
{
    use ForCompany;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice;

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'profile';

    protected string $view = 'filament.seller.pages.company-profile';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function getNavigationLabel(): string
    {
        return __('Company profile');
    }

    public function getTitle(): string
    {
        return __('Company profile');
    }

    public function getSubheading(): string
    {
        return $this->canManage()
            ? __('Buyers see this on your auctions.')
            : __('Only the company owner can change the profile.');
    }

    public function mount(): void
    {
        $company = $this->company();

        $this->form->fill([
            'name' => $company->name,
            'legal_name' => $company->legal_name,
            'email' => $company->email,
            'phone' => $company->phone,
            'address' => $company->address,
            'about' => $company->about,
            'logo' => $company->logo_path,
            'banner' => $company->banner_path,
            'accent_color' => $company->accent_color,
            'website_url' => $company->website_url,
            'facebook_url' => $company->facebook_url,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->disabled(fn () => ! $this->canManage())
            ->components([
                Section::make(__('Public profile'))
                    ->description(__('Your name, how to reach you, and a few words about what you sell.'))
                    ->aside()
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')->label(__('Company name'))->required()->minLength(2)->maxLength(255),
                        TextInput::make('legal_name')->label(__('Legal name'))->maxLength(255)->helperText(__('As on your trade licence, if different.')),
                        TextInput::make('email')->label(__('Contact email'))->email()->maxLength(255),
                        TextInput::make('phone')->label(__('Phone'))->tel()->maxLength(32),
                        TextInput::make('address')->label(__('Address'))->maxLength(500)->columnSpanFull(),
                        Textarea::make('about')->label(__('About the company'))->rows(5)->maxLength(5000)->columnSpanFull(),
                    ]),
                Section::make(__('Logo'))
                    ->description(__('A square picture works best. JPG, PNG or WebP, up to 4 MB.'))
                    ->aside()
                    ->schema([
                        FileUpload::make('logo')
                            ->hiddenLabel()
                            ->image()
                            ->avatar()
                            ->disk('public')
                            ->maxSize(4096)
                            ->storeFiles(false),
                    ]),
                Section::make(__('Storefront'))
                    ->description(__('Your public seller page: a wide banner, a colour for its header and links buyers may follow. Contact details are never shown there.'))
                    ->aside()
                    ->columns(2)
                    ->schema([
                        FileUpload::make('banner')
                            ->label(__('Banner'))
                            ->helperText(__('About 1600 × 400 pixels. JPG, PNG or WebP, up to 4 MB.'))
                            ->image()
                            ->disk('public')
                            ->maxSize(4096)
                            ->storeFiles(false)
                            ->columnSpanFull(),
                        ColorPicker::make('accent_color')->label(__('Accent colour'))->hex()->regex('/^#[0-9a-fA-F]{6}$/'),
                        TextInput::make('website_url')->label(__('Website'))->url()->maxLength(255)->placeholder('https://'),
                        TextInput::make('facebook_url')->label(__('Facebook page'))->url()->maxLength(255)->placeholder('https://facebook.com/…'),
                    ]),
            ]);
    }

    public function save(UpdateCompanyProfile $update): void
    {
        $state = $this->form->getState();
        $logo = $state['logo'] ?? null;
        $banner = $state['banner'] ?? null;
        $company = $this->company();

        // A new upload is a file; an untouched picture is its path; a removed one is null.
        try {
            $update->handle(
                $this->user(),
                $company,
                $state,
                $logo instanceof UploadedFile ? $logo : null,
                $logo === null && $company->logo_path !== null,
                $banner instanceof UploadedFile ? $banner : null,
                $banner === null && $company->banner_path !== null,
            );
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn (array $messages, string $key) => ["data.{$key}" => $messages])->all());
        }

        $this->form->fill([...$state, 'logo' => $company->logo_path, 'banner' => $company->banner_path]);
        $this->toast(__('Company profile saved.'));
    }
}
