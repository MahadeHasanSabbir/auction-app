<?php

namespace App\Filament\Admin\Pages;

use App\Actions\Settings\UpdateSettings;
use App\Filament\Support\Outcome;
use App\Filament\Support\PercentInput;
use App\Models\Setting;
use App\Services\Settings\Settings;
use App\Support\Money;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;

/**
 * Marketplace settings the system owner can change without a deploy
 * (Settings::definitions()). Each field shows the install default; saving
 * the default removes the override.
 *
 * @property-read Schema $form
 */
class MarketplaceSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?int $navigationSort = 24;

    protected static ?string $slug = 'settings';

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
        return __('Settings');
    }

    public function getTitle(): string
    {
        return __('Marketplace settings');
    }

    public function mount(Settings $settings): void
    {
        $state = [];

        foreach (Settings::definitions() as $key => $definition) {
            // A percentage field formats basis points itself.
            $state[self::field($key)] = $definition['kind'] === 'percent'
                ? $settings->get($key)
                : self::toInput($definition['kind'], $settings->get($key));
        }

        $this->form->fill($state);
    }

    public function form(Schema $schema): Schema
    {
        $settings = app(Settings::class);
        $definitions = collect(Settings::definitions());

        return $schema
            ->statePath('data')
            ->components(collect(Settings::groups())->map(fn (string $title, string $group) => Section::make($title)
                ->aside()
                ->columns(2)
                ->schema($definitions->where('group', $group)->map(function (array $definition, string $key) use ($settings) {
                    $field = match ($definition['kind']) {
                        'percent' => PercentInput::make(self::field($key))->dehydrateStateUsing(null),
                        'money' => TextInput::make(self::field($key))->suffix((string) config('auction.currency'))->inputMode('decimal')->rule('regex:/^\d{1,12}(\.\d{1,2})?$/'),
                        default => TextInput::make(self::field($key))->integer()->minValue($definition['min'])->maxValue($definition['max']),
                    };

                    return $field->label($definition['label'])
                        ->required()
                        ->helperText(trim($definition['help'].' '.__('Default: :value', ['value' => self::toInput($definition['kind'], $settings->default($key))])));
                })->values()->all()))
                ->values()
                ->all());
    }

    public function save(UpdateSettings $action): void
    {
        $state = $this->form->getState();
        $values = [];

        foreach (Settings::definitions() as $key => $definition) {
            $values[$key] = self::fromInput($definition['kind'], (string) $state[self::field($key)]);
        }

        Outcome::attempt(fn () => $action->handle(Outcome::user(), $values), __('Settings saved.'));
    }

    /** A form field name for a settings key; dots would nest the state. */
    private static function field(string $key): string
    {
        return str_replace('.', '__', $key);
    }

    private static function toInput(string $kind, int $value): string
    {
        return match ($kind) {
            'percent' => PercentInput::format($value),
            'money' => Money::minor($value)->toDecimal(),
            default => (string) $value,
        };
    }

    private static function fromInput(string $kind, string $value): int
    {
        return match ($kind) {
            'percent' => PercentInput::parse($value),
            'money' => Money::parse($value)->amount,
            default => (int) $value,
        };
    }
}
