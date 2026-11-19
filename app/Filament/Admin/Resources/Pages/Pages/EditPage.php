<?php

namespace App\Filament\Admin\Resources\Pages\Pages;

use App\Actions\Content\UpdatePage;
use App\Filament\Admin\Resources\Pages\PageResource;
use App\Filament\Support\FormErrors;
use App\Filament\Support\Outcome;
use App\Models\Page;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/** @property-read Page $record */
class EditPage extends EditRecord
{
    protected static string $resource = PageResource::class;

    public function getSubheading(): string
    {
        return $this->record->slug->label();
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Page $record */
        try {
            return app(UpdatePage::class)->handle(Outcome::user(), $record, $data);
        } catch (ValidationException $e) {
            FormErrors::rethrow($e, ['title_en', 'title_bn', 'body_en', 'body_bn']);
        }
    }

    protected function getRedirectUrl(): string
    {
        return PageResource::getUrl('index');
    }
}
