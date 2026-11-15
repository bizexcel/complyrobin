<?php

use App\Core\Numbering\Numbering;
use App\Core\Organisations\Models\Organisation;
use App\Core\Tenancy\Exceptions\MissingTenantContext;
use App\Core\Tenancy\Facades\Tenancy;
use Illuminate\Support\Carbon;

it('issues consecutive references per organisation and series', function (): void {
    [$acme, $globex] = Organisation::factory()->count(2)->create();
    $next = fn (Organisation $organisation, string $series = 'credential', string $prefix = 'CRD'): string => Tenancy::run(
        $organisation,
        fn (): string => app(Numbering::class)->next($series, $prefix),
    );

    expect([$next($acme), $next($acme), $next($globex), $next($acme, 'asset', 'AST'), $next($acme)])
        ->toBe(['CRD-0001', 'CRD-0002', 'CRD-0001', 'AST-0001', 'CRD-0003']);
});

it('restarts yearly series on 1 January in the organisation\'s time zone', function (): void {
    $organisation = Organisation::factory()->create(['timezone' => 'Asia/Kuala_Lumpur']);
    $next = fn (): string => Tenancy::run($organisation, fn (): string => app(Numbering::class)->next('incident', 'INC', restartsYearly: true));

    // 31 Dec 2026 16:30 UTC is already 1 Jan 2027 00:30 in Kuala Lumpur.
    $this->travelTo(Carbon::parse('2026-12-31 15:00', 'UTC'));
    $lastOf2026 = [$next(), $next()];
    $this->travelTo(Carbon::parse('2026-12-31 16:30', 'UTC'));

    expect([...$lastOf2026, $next()])->toBe(['INC-2026-0001', 'INC-2026-0002', 'INC-2027-0001']);
});

it('needs a current organisation', function (): void {
    app(Numbering::class)->next('credential', 'CRD');
})->throws(MissingTenantContext::class);
