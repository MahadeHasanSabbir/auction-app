<?php

namespace App\Filament\Seller\Pages;

use App\Actions\Companies\UpdateCompanyProfile;
use App\Filament\Seller\Pages\Concerns\ForCompany;
use BackedEnum;
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
            ]);
    }

    public function save(UpdateCompanyProfile $update): void
    {
        $state = $this->form->getState();
        $logo = $state['logo'] ?? null;

        // A new upload is a file; an untouched logo is its path; a removed one is null.
        $newLogo = $logo instanceof UploadedFile ? $logo : null;
        $removeLogo = $logo === null && $this->company()->logo_path !== null;

        try {
            $update->handle($this->user(), $this->company(), $state, $newLogo, $removeLogo);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn (array $messages, string $key) => ["data.{$key}" => $messages])->all());
        }

        $this->form->fill([...$state, 'logo' => $this->company()->logo_path]);
        $this->toast(__('Company profile saved.'));
    }
}
