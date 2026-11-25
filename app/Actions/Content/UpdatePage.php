<?php

namespace App\Actions\Content;

use App\Models\Page;
use App\Models\User;
use App\Support\SafeHtml;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Platform staff edit a text page. The bodies are sanitised here, so the
 * site can print them as HTML; the change goes to the `admin` activity log.
 */
final class UpdatePage
{
    /**
     * @param  array<string, mixed>  $data  title_en, title_bn, body_en, body_bn
     *
     * @throws ValidationException when a field is not acceptable
     */
    public function handle(User $staff, Page $page, array $data): Page
    {
        Gate::forUser($staff)->authorize('update', $page);

        $validated = Validator::make($data, [
            'title_en' => ['required', 'string', 'min:2', 'max:255'],
            'title_bn' => ['nullable', 'string', 'max:255'],
            'body_en' => ['nullable', 'string', 'max:200000'],
            'body_bn' => ['nullable', 'string', 'max:200000'],
        ])->validate();

        DB::transaction(function () use ($staff, $page, $validated): void {
            $page->fill([
                'title_en' => trim($validated['title_en']),
                'title_bn' => filled($validated['title_bn'] ?? null) ? trim($validated['title_bn']) : null,
                'body_en' => SafeHtml::clean($validated['body_en'] ?? null),
                'body_bn' => SafeHtml::clean($validated['body_bn'] ?? null),
            ]);
            $page->updated_by = $staff->id;
            $page->save();

            activity('admin')
                ->causedBy($staff)
                ->performedOn($page)
                ->event('page_updated')
                ->log('Page updated: '.$page->slug->value);
        });

        return $page;
    }
}
