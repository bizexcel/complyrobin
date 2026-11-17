<?php

namespace App\Billing\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WebhookReceived
{
    use Dispatchable, SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param  array<string, mixed>  $payload  The webhook payload.
     */
    public function __construct(public array $payload) {}
}
