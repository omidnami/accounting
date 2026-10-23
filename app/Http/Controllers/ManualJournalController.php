<?php

namespace App\Http\Controllers;

use App\Enums\JournalEntryKind;
use App\Enums\JournalEntryStatus;
use App\Http\Requests\ManualJournalRequest;
use App\Models\JournalEntry;
use App\Models\Store;
use App\Services\Accounting\ManualJournalService;
use App\Support\Digits;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ManualJournalController extends Controller
{
    public function index(Request $request, ManualJournalService $journals): Response
    {
        $this->authorizeAccess($request);
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'entry_kind' => ['nullable', Rule::enum(JournalEntryKind::class)],
            'status' => ['nullable', Rule::enum(JournalEntryStatus::class)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        $filters = [
            'search' => trim(Digits::toLatin((string) ($validated['search'] ?? ''))),
            'entry_kind' => $validated['entry_kind'] ?? null,
            'status' => $validated['status'] ?? null,
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
        ];
        $result = $journals->paginate($this->mainStore(), $filters);

        return Inertia::render('journals/Index', [
            ...$result,
            'filters' => $filters,
        ]);
    }

    public function create(Request $request, ManualJournalService $journals): Response
    {
        $this->authorizeAccess($request);
        $validated = $request->validate([
            'kind' => ['nullable', Rule::in([
                JournalEntryKind::Manual->value,
                JournalEntryKind::Opening->value,
            ])],
        ]);

        return Inertia::render('journals/Form', [
            'entry' => null,
            'default_kind' => $validated['kind'] ?? JournalEntryKind::Manual->value,
            ...$journals->formOptions($this->mainStore()),
        ]);
    }

    public function store(
        ManualJournalRequest $request,
        ManualJournalService $journals,
    ): RedirectResponse {
        try {
            $entry = $journals->createDraft(
                $this->mainStore(),
                $request->validated(),
                $request->user(),
            );
        } catch (DomainException $exception) {
            throw ValidationException::withMessages([
                'journal' => $exception->getMessage(),
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "پیش‌نویس سند {$entry->entry_number} ایجاد شد.",
        ]);

        return to_route('journals.show', $entry);
    }

    public function show(
        Request $request,
        JournalEntry $journalEntry,
        ManualJournalService $journals,
    ): Response {
        $this->authorizeAccess($request);

        try {
            $detail = $journals->detailForStore($this->mainStore(), $journalEntry);
        } catch (DomainException $exception) {
            abort(404, $exception->getMessage());
        }

        return Inertia::render('journals/Show', [
            'entry' => $detail,
        ]);
    }

    public function edit(
        Request $request,
        JournalEntry $journalEntry,
        ManualJournalService $journals,
    ): Response {
        $this->authorizeAccess($request);

        try {
            $entry = $journals->editablePayload(
                $this->mainStore(),
                $journalEntry,
            );
        } catch (DomainException $exception) {
            abort(404, $exception->getMessage());
        }

        return Inertia::render('journals/Form', [
            'entry' => $entry,
            'default_kind' => $entry['entry_kind'],
            ...$journals->formOptions($this->mainStore()),
        ]);
    }

    public function update(
        ManualJournalRequest $request,
        JournalEntry $journalEntry,
        ManualJournalService $journals,
    ): RedirectResponse {
        try {
            $entry = $journals->updateDraft(
                $this->mainStore(),
                $journalEntry,
                $request->validated(),
            );
        } catch (DomainException $exception) {
            throw ValidationException::withMessages([
                'journal' => $exception->getMessage(),
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "پیش‌نویس سند {$entry->entry_number} ذخیره شد.",
        ]);

        return to_route('journals.show', $entry);
    }

    public function post(
        Request $request,
        JournalEntry $journalEntry,
        ManualJournalService $journals,
    ): RedirectResponse {
        $this->authorizeAccess($request);

        try {
            $entry = $journals->post(
                $this->mainStore(),
                $journalEntry,
                $request->user(),
            );
        } catch (DomainException $exception) {
            throw ValidationException::withMessages([
                'journal' => $exception->getMessage(),
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "سند {$entry->entry_number} ثبت نهایی شد.",
        ]);

        return to_route('journals.show', $entry);
    }

    public function destroy(
        Request $request,
        JournalEntry $journalEntry,
        ManualJournalService $journals,
    ): RedirectResponse {
        $this->authorizeAccess($request);

        try {
            $journals->deleteDraft($this->mainStore(), $journalEntry);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages([
                'journal' => $exception->getMessage(),
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'پیش‌نویس سند حذف شد.',
        ]);

        return to_route('journals.index');
    }

    private function authorizeAccess(Request $request): void
    {
        abort_unless($request->user()?->canApproveJournalEntries(), 403);
    }

    private function mainStore(): Store
    {
        return Store::query()->where('code', 'MAIN')->firstOrFail();
    }
}
