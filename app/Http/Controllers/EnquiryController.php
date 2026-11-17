<?php

namespace App\Http\Controllers;

use App\Core\Enquiries\Actions\SubmitEnquiry;
use App\Core\Enquiries\Rules\Turnstile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The contact form on www.complyrobin.com posts here. Turnstile-checked, rate limited, and CORS limited to www.
 */
class EnquiryController extends Controller
{
    public function __invoke(Request $request, SubmitEnquiry $submitEnquiry): JsonResponse
    {
        $request->validate([
            'cf-turnstile-response' => ['required', new Turnstile($request->ip())],
        ], [
            'cf-turnstile-response.required' => 'We couldn\'t confirm this came from a person. Reload the page and send it again.',
        ]);

        $submitEnquiry->handle($request->only(['name', 'email', 'company', 'message']));

        return response()->json(['message' => 'Thank you. We have your message and will reply by email.'], 201);
    }
}
