<?php

namespace App\Filament\Admin\Pages;

use App\Actions\Settings\UpdateBranding;
use App\Filament\Support\Outcome;
use App\Models\Setting;
use App\Services\Branding\Branding as BrandingService;
use BackedEnum;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;

/**
 * The platform's brand colour, logo and favicon (system owner). The colour
 * becomes the site's and the panels' brand scale and the email header band.
 * Pictures are not stored by the form: the action re-encodes them.
 *
 * @property-read Schema $form
 */
class Branding extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSwatch;

    protected static ?int $navigationSort = 25;

    protected static ?string $slug = 'branding';

    protected string $view = 'filament.admin.pages.marketplace-settings';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Gate::allows('manage', Setting::class);
    }

    public static function getNavigationGroup(): string
    {
        return __('Marketplace');
    }

    public static function getNavigationLabel(): string
    {
        return __('Branding');
    }

    public function getTitle(): string
    {
        return __('Branding');
    }

    public function mount(BrandingService $branding): void
    {
        $this->form->fill([
            'color' => $branding->color(),
            'logo' => $branding->logoPath(),
            'favicon' => $branding->faviconPath(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('Brand colour'))
                    ->description(__('Buttons, links and highlights on the site and in both panels, and the band at the top of every email. Leave empty for the built-in blue.'))
                    ->aside()
                    ->schema([
                        ColorPicker::make('color')->label(__('Colour'))->hex()->regex('/^#[0-9a-fA-F]{6}$/'),
                    ]),
                Section::make(__('Logo'))
                    ->description(__('Shown in the site header, the panels, emails and invoices instead of the name. A wide picture with a transparent background works best; JPG, PNG or WebP, up to 2 MB.'))
                    ->aside()
                    ->schema([
                        FileUpload::make('logo')->hiddenLabel()->image()->disk('public')->maxSize(2048)->storeFiles(false),
                    ]),
                Section::make(__('Favicon'))
                    ->description(__('The small icon in the browser tab. A square picture, at least 64 pixels.'))
                    ->aside()
                    ->schema([
                        FileUpload::make('favicon')->hiddenLabel()->image()->avatar()->disk('public')->maxSize(1024)->storeFiles(false),
                    ]),
            ]);
    }

    public function save(UpdateBranding $action, BrandingService $branding): void
    {
        $state = $this->form->getState();
        $logo = $state['logo'] ?? null;
        $favicon = $state['favicon'] ?? null;

        // A new upload is a file; an untouched picture is its path; a removed one is null.
        $saved = Outcome::attempt(fn () => $action->handle(
            Outcome::user(),
            $state['color'] ?? null,
            $logo instanceof UploadedFile ? $logo : null,
            $logo === null && $branding->logoPath() !== null,
            $favicon instanceof UploadedFile ? $favicon : null,
            $favicon === null && $branding->faviconPath() !== null,
        ), __('Branding saved. Reload the page to see it.'));

        if ($saved) {
            $branding->forget();
            $this->mount($branding);
        }
    }
}
