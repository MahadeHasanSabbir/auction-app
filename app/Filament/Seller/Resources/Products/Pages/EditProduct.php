<?php

namespace App\Filament\Seller\Resources\Products\Pages;

use App\Filament\Seller\Resources\Products\Pages\Concerns\SavesProduct;
use App\Filament\Seller\Resources\Products\ProductResource;
use App\Models\Product;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/** @property-read Product $record */
class EditProduct extends EditRecord
{
    use SavesProduct;

    protected static string $resource = ProductResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return [...$data, 'images' => $this->record->images()->pluck('path')->all()];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Product $record */
        return $this->saveProduct($record, $data);
    }

    protected function getRedirectUrl(): string
    {
        return ProductResource::getUrl('view', ['record' => $this->record]);
    }

    protected function getHeaderActions(): array
    {
        return [ProductResource::deleteAction()];
    }
}
