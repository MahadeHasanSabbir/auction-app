<?php

namespace App\Filament\Seller\Resources\Products\Pages;

use App\Filament\Seller\Resources\Products\Pages\Concerns\SavesProduct;
use App\Filament\Seller\Resources\Products\ProductResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateProduct extends CreateRecord
{
    use SavesProduct;

    protected static string $resource = ProductResource::class;

    protected static bool $canCreateAnother = false;

    public function getTitle(): string
    {
        return __('Add product');
    }

    protected function handleRecordCreation(array $data): Model
    {
        return $this->saveProduct(null, $data);
    }

    protected function getRedirectUrl(): string
    {
        return ProductResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
