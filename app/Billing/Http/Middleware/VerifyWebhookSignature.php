<?php

namespace App\Billing\Http\Middleware;

use App\Billing\Airwallex\WebhookSignature;
use App\Billing\Exceptions\InvalidWebhookSignature;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class VerifyWebhookSignature
{
    /**
     * Handle the incoming request.
     *
     * @param  Closure(Request): Response  $next
     *
     * @throws AccessDeniedHttpException
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            WebhookSignature::verifyHeader(
                $request->getContent(),
                $request->header('x-timestamp'),
                $request->header('x-signature'),
                config('billing.webhook.secret'),
                (int) config('billing.webhook.tolerance'),
            );
        } catch (InvalidWebhookSignature $exception) {
            throw new AccessDeniedHttpException($exception->getMessage(), $exception);
        }

        return $next($request);
    }
}
