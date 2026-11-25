<?php

namespace App\Filament\Seller\Pages;

use App\Actions\Kyc\SubmitKyc;
use App\Enums\KycDocumentType;
use App\Enums\KycStatus;
use App\Filament\Seller\Pages\Concerns\ForCompany;
use App\Models\Company;
use App\Models\KycRequest;
use App\Support\Kyc;
use BackedEnum;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * The company's identity check: where it stands and, while documents are
 * wanted, the owner's upload form (owner's ID and the trade licence).
 *
 * @property-read Schema $form
 */
class Verification extends Page
{
    use ForCompany;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?int $navigationSort = 11;

    protected static ?string $slug = 'verification';

    protected string $view = 'filament.seller.pages.verification';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function getNavigationLabel(): string
    {
        return __('Verification');
    }

    public function getTitle(): string
    {
        return __('Verification');
    }

    public function getSubheading(): string
    {
        return __('The platform checks every company once before it can list or withdraw.');
    }

    public static function getNavigationBadge(): ?string
    {
        $company = filament()->getTenant();

        return $company instanceof Company && Kyc::awaitsDocuments($company) ? '!' : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'warning';
    }

    public function mount(): void
    {
        $this->form->fill(['id_type' => KycDocumentType::NationalId->value]);
    }

    public function status(): KycStatus
    {
        return $this->company()->refresh()->kyc_status;
    }

    public function awaitsDocuments(): bool
    {
        return Kyc::awaitsDocuments($this->company());
    }

    public function latest(): ?KycRequest
    {
        return KycRequest::query()->where('company_id', $this->company()->id)->with('documents')->latest('id')->first();
    }

    public function form(Schema $schema): Schema
    {
        $file = fn (string $name) => FileUpload::make($name)
            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])
            ->maxSize((int) config('auction.kyc.max_kb'))
            ->storeFiles(false);

        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('Documents'))
                    ->description(__('JPG, PNG, WebP or PDF, up to :mb MB each. Only platform staff can see them.', ['mb' => intdiv((int) config('auction.kyc.max_kb'), 1024)]))
                    ->aside()
                    ->columns(2)
                    ->schema([
                        Select::make('id_type')->label(__('Owner’s identity document'))
                            ->options(collect([KycDocumentType::NationalId, KycDocumentType::Passport, KycDocumentType::DrivingLicence])
                                ->mapWithKeys(fn (KycDocumentType $type) => [$type->value => $type->label()])->all())
                            ->required()
                            ->selectablePlaceholder(false),
                        $file('id_file')->label(__('File'))->required(),
                        $file('licence_file')->label(__('Trade licence'))->required(),
                        $file('other_file')->label(__('Another document (optional)'))->helperText(__('For example a tax certificate.')),
                    ]),
            ]);
    }

    public function submit(SubmitKyc $submit): void
    {
        $state = $this->form->getState();

        $documents = array_values(array_filter([
            $this->document(KycDocumentType::from((string) $state['id_type']), $state['id_file'] ?? null),
            $this->document(KycDocumentType::TradeLicence, $state['licence_file'] ?? null),
            $this->document(KycDocumentType::Other, $state['other_file'] ?? null),
        ]));

        try {
            $submit->handle($this->user(), $this->company(), $documents);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn (array $messages) => ['data.id_file' => $messages])->all());
        }

        $this->form->fill(['id_type' => KycDocumentType::NationalId->value]);
        $this->toast(__('Documents sent. The platform will check them soon.'));
    }

    /** @return array{type: KycDocumentType, file: UploadedFile}|null */
    private function document(KycDocumentType $type, mixed $file): ?array
    {
        $file = is_array($file) ? reset($file) : $file;

        return $file instanceof UploadedFile ? ['type' => $type, 'file' => $file] : null;
    }
}
