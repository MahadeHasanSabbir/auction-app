<?php

namespace App\Filament\Admin\Resources\Notices\Pages;

use App\Filament\Admin\Resources\Notices\NoticeResource;
use App\Models\Notice;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Support\Facades\Auth;

class ManageNotices extends ManageRecords
{
    protected static string $resource = NoticeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->using(function (array $data): Notice {
                $notice = new Notice($data);
                $notice->created_by = (int) Auth::id();
                $notice->save();

                return $notice;
            }),
        ];
    }
}
