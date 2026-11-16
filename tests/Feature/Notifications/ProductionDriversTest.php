<?php

use Aws\Sdk;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Resend\Laravel\Transport\ResendTransportFactory;

/**
 * Production sends mail through Resend and runs jobs on Laravel Cloud's managed queue. These fail in CI, rather than
 * on a deploy, if either package goes missing.
 */
it('builds the Resend mailer production sends with', function (): void {
    config(['services.resend.key' => 're_test_dummy']);

    expect(Mail::mailer('resend')->getSymfonyTransport())->toBeInstanceOf(ResendTransportFactory::class);
});

it('has the AWS SDK that Laravel Cloud\'s managed queue needs', function (): void {
    expect(class_exists(Sdk::class))->toBeTrue();
});

it('keeps Resend\'s unsigned webhook route switched off until bounces are handled', function (): void {
    expect(Route::has('resend.webhook'))->toBeFalse();
});
