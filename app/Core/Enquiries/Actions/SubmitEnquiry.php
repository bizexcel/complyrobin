<?php

namespace App\Core\Enquiries\Actions;

use App\Core\Enquiries\Models\Enquiry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SubmitEnquiry
{
    /**
     * Record a message from the www contact form for staff to answer. The caller has already checked it came from a
     * person (Turnstile); this checks the fields themselves.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function handle(array $data): Enquiry
    {
        /** @var array{name: string, email: string, company?: string|null, message: string} $validated */
        $validated = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'max:255', 'email'],
            'company' => ['nullable', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:5000'],
        ])->validate();

        return DB::transaction(fn (): Enquiry => Enquiry::query()->create([
            'name' => trim($validated['name']),
            'email' => Str::lower(trim($validated['email'])),
            'company' => filled($validated['company'] ?? null) ? trim((string) $validated['company']) : null,
            'message' => trim($validated['message']),
        ]));
    }
}
