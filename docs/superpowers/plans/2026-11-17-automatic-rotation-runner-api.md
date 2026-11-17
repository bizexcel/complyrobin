# Automatic Rotation: Runner API Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a customer-side "runner" ask ComplyRobin which credentials are due, rotate them in the customer's own accounts, and report back, so ComplyRobin records the rotation as evidence without ever holding a key or an admin token.

**Architecture:** ComplyRobin stays the calendar and the record book. An organisation creates a **runner** (a named token, stored only as a hash), switches on **automatic rotation** for a credential (non-secret ids: which OpenAI service account makes the key, which Laravel Cloud secret receives it), and the runner polls two JSON endpoints: one to claim due work (a **rotation run**), one to report how it went. A successful report writes the same `credential_rotations` row a person's "Record rotation" writes. The runner itself (a PHP CLI packaged as a GitHub Action and a Laravel package) is a separate plan in a separate public repository; this plan fixes the contract it will follow.

**Tech Stack:** PHP 8.5, Laravel 13, Filament 5, Postgres 17 with row-level security, Pest 5. No new packages.

**Spec:** `docs/superpowers/specs/2026-11-15-complyrobin-design.md` (Task 1 adds the "Automatic rotation" section this plan implements). Background and the two-design comparison: https://claude.ai/artifact/WWs5ZdgqCvVyCLe4h3cjgC

## Global Constraints

- **No secret is ever stored, logged or accepted.** No endpoint takes a key value. Every string a runner reports passes `App\Modules\Credentials\Rules\NotASecret` and a length cap. The only secret-like value ComplyRobin keeps is the runner's own token, as a sha-256 hash in a `#[Hidden]` column (as `calendar_feeds.token`).
- **Tenant isolation.** New tables use `Schema::tenantTable()` and `tenantForeign()`; new models use `BelongsToOrganisation`, `HasUuids`, `LogsTenantActivity`. Work runs inside `Tenancy::run($organisation, …)`. No `Tenancy::bypass()` in this plan. Every new tenant table gets a row in `populatedOrganisation()` (`tests/Pest.php`) in the task that creates it.
- **Actions own writes.** One use case per `final readonly` class with a single `handle()`: authorise, then `DB::transaction()`. Domain code never imports `Filament\*`.
- **Ids.** UUIDv7 keys. Screens, emails and exports show `reference` or a name, never an id.
- **Migrations** are made with `php artisan make:migration --no-interaction` and run with `php artisan migrate --database=pgsql_migrator`. Timestamps are `timestampTz`; enums are strings cast to PHP enums; booleans start `is_`/`has_`.
- **Copy** follows `.ai/rules/brand.md`: sentence case, British spelling, "you", buttons are verbs, dates as `13 Nov 2026`, no emoji, never invite anyone to type or paste a secret value.
- **Read first:** `.ai/rules/index.md` and every rule file whose globs match a file you touch. Read the `testing-best-practices` skill before writing tests.
- **Per task:** run the task's tests with `php artisan test --compact <file>`, then `vendor/bin/pint --dirty --format agent`, then commit. After adding classes run `composer dump-autoload` before arch tests.
- **Plan gate:** automatic rotation is a Team feature (`Feature::AutomaticRotation`), checked with `Entitlements::allows()`, never by plan name.

## Names used throughout

| Thing | Name |
|---|---|
| Tables | `runners`, `automatic_rotations`, `rotation_runs` |
| Models (`App\Modules\Credentials\Models`) | `Runner`, `AutomaticRotation`, `RotationRun` |
| Enums (`App\Modules\Credentials\Enums`) | `RotationSource` (`OpenAi = 'openai'`), `RotationDestination` (`LaravelCloud = 'laravel_cloud'`), `RotationRunStatus` (`Claimed`, `Succeeded`, `Failed`, `Abandoned`; values lower-case) |
| Config | `config('credentials.automatic_rotation')`: `claim_expires_minutes => 60`, `retry_after_hours => 6`, `grace_hours => 48`, `max_jobs_per_claim => 5`, `clock_skew_minutes => 5` |
| Routes | `POST /api/runner/{organisation}/claims` (`runner.claims`), `POST /api/runner/{organisation}/reports` (`runner.reports`) |
| Token | `crr_` followed by 60 characters of `[A-Za-z0-9]`, sent as `Authorization: Bearer …` |
| UI words | "Rotation runner" (the helper), "Automatic rotation" (the setting), "Rotate now" (the button) |

## The runner contract

Both endpoints: no session, cookies or CSRF. `{organisation}` is the organisation's slug (a previous slug also works). Any problem with the slug or the token (unknown, revoked, another organisation's) is `401 {"message":"Unauthenticated."}`. A plan without the feature is `200 {"jobs":[]}` for claims and `403` for reports.

**Claim** — `POST /api/runner/{organisation}/claims`, empty body. Response `200`:

```json
{
  "jobs": [
    {
      "run": "0193…uuid",
      "credential": {"reference": "CRD-0042", "name": "OpenAI production key"},
      "source": {"type": "openai", "project_id": "proj_abc", "service_account_id": "svc_acct_abc", "key_lifetime_days": null},
      "destinations": [
        {"type": "laravel_cloud", "secret_id": "sec-1", "secret_name": "OPENAI_API_KEY", "environment_ids": ["env-1"], "redeploy": true}
      ],
      "grace_hours": 48
    }
  ]
}
```

What the runner does with a job (Plan 2, recorded here so both sides agree): mint a new key for the service account; seal it and update each destination secret; redeploy the listed environments when `redeploy` is true; set every *other* key of that service account to expire in `grace_hours`; report. The service account is dedicated to one credential, which is what makes "every other key" safe and cleans up keys left by a half-finished run.

**Report** — `POST /api/runner/{organisation}/reports`:

```json
{"run": "0193…", "outcome": "succeeded", "rotated_at": "2026-11-17T10:00:00Z",
 "new_key": {"id": "key_abc", "hint": "AB12", "expires_at": null},
 "old_keys_expire_at": "2026-11-19T10:00:00Z", "deployments": ["dep-1"]}
```

```json
{"run": "0193…", "outcome": "failed", "step": "push", "message": "Laravel Cloud answered 422."}
```

`step` is one of `mint`, `push`, `deploy`, `retire`. Responses: `200 {"recorded":true}`; `404` when the run isn't this runner's; `409 {"message":"This run has already been reported."}` when the run is finished with a different outcome; `422` with Laravel's validation errors. Reporting the same outcome twice is `200` and writes nothing new.

## Review Focus

1. **A runner reports the key itself by mistake** (in `new_key.id`, `hint`, `message` or a deployment id). Expected: `422`, nothing stored, nothing in the activity log. Test in Task 5.
2. **The runner's report is lost and it retries, or reports after the hour is up.** Expected: one rotation row, never two; a late success for an abandoned run still counts. Tests in Task 5.
3. **Two claims arrive at once, or the runner polls every minute while failing.** Expected: a credential is never handed out twice while a run is open, and not again within 6 hours of a failure. Tests in Task 4.
4. **The organisation drops to Free, the credential is revoked or deleted, or the runner is revoked between claim and report.** Expected: no new jobs; a revoked runner gets `401`; a report for a revoked credential is refused with `422` and the run is marked failed. Tests in Tasks 4 and 5.
5. **A token from organisation A is used with organisation B's slug, or a run id from A is reported to B.** Expected: `401` and `404`. Tests in Task 6, and the isolation suite covers the tables.

---

### Task 1: Feature flag, settings and spec

**Files:**
- Modify: `app/Core/Entitlements/Enums/Feature.php`, `config/plans.php`, `config/credentials.php`
- Modify: `docs/superpowers/specs/2026-11-15-complyrobin-design.md`
- Test: `tests/Feature/Entitlements/` (add to the existing plan/feature test file there)

**Interfaces:**
- Produces: `Feature::AutomaticRotation` (`'automatic_rotation'`); `config('credentials.automatic_rotation.*')` with the five values in "Names used throughout".

- [ ] **Step 1: Write the failing test** in the existing entitlements test file:

```php
it('includes automatic rotation on Team and Enterprise, not on Free', function (): void {
    expect(Plan::Free->allows(Feature::AutomaticRotation))->toBeFalse()
        ->and(Plan::Team->allows(Feature::AutomaticRotation))->toBeTrue()
        ->and(Plan::Enterprise->allows(Feature::AutomaticRotation))->toBeTrue();
});
```

- [ ] **Step 2: Run it** — expected: FAIL, undefined case `AutomaticRotation`.
- [ ] **Step 3: Add the case** with the doc comment `/** A rotation runner swaps keys on schedule and records the evidence. */`, add `'automatic_rotation'` to `$paidFeatures` in `config/plans.php`, and add the `automatic_rotation` block to `config/credentials.php` with a one-line comment per value.
- [ ] **Step 4: Update the spec.** In "Credentials register", replace the "Vendor integrations" bullet under "Later, not in v1" with a new subsection **"Automatic rotation (customer-side runner)"** stating: the two-design decision (hosted rotation is deferred to Phase 7 with the vault; the runner is built now), that ComplyRobin stores no admin token and no key, the three tables and their purpose in one line each, and the runner contract above copied verbatim. In section 4 add "**Phase 2b: Automatic rotation**" after Phase 2 with exit criteria: the five Review Focus lines hold, and the isolation suite covers the three tables. Leave "Never invite anyone to type or paste a secret value" in `brand.md` untouched; it still holds.
- [ ] **Step 5: Run the test** — expected: PASS. Pint. Commit `feat(credentials): automatic rotation feature flag, settings and spec`.

---

### Task 2: Runners

**Files:**
- Create: migration `create_runners_table`, `app/Modules/Credentials/Models/Runner.php`, `database/factories/RunnerFactory.php`
- Create: `app/Modules/Credentials/Actions/CreateRunner.php`, `RevokeRunner.php`, `app/Modules/Credentials/Support/RunnerTokens.php`
- Modify: `tests/Pest.php` (`populatedOrganisation()`)
- Test: `tests/Feature/Credentials/RunnersTest.php`

**Interfaces:**
- Produces:
  - Table `runners`: `name` string(80); `token` string(64) unique, comment "sha-256 of the runner's token"; `last_seen_at` timestampTz nullable; `revoked_at` timestampTz nullable; `timestampsTz()`; `unique(organisation_id, name)`.
  - `Runner` model: `#[Fillable(['name', 'token'])]`, `#[Hidden(['token'])]`, casts `last_seen_at`, `revoked_at` to datetime. Runners are never deleted (runs keep pointing at them); revoking sets `revoked_at`.
  - `CreateRunner::handle(User $actor, array $data): array{runner: Runner, token: string}` — `$data` is `array{name: string}`. Needs `Ability::ManageCredentials` and `Feature::AutomaticRotation`. The token is `'crr_'.Str::random(60)`; only its hash is stored.
  - `RevokeRunner::handle(User $actor, Runner $runner): Runner` — needs `Ability::ManageCredentials`; sets `revoked_at` once (revoking twice changes nothing).
  - `RunnerTokens::find(string $token): ?Runner` — inside the current organisation, the runner whose `token` is `hash('sha256', $token)` and `revoked_at` is null; `null` for anything not matching `/^crr_[A-Za-z0-9]{60}$/`.
  - `RunnerFactory` with state `revoked()`, and a test helper in the test file `runnerFor(Organisation $organisation, string $name = 'GitHub'): array{runner: Runner, token: string}` that goes through `CreateRunner` as the owner.

- [ ] **Step 1: Write the failing tests** (`beforeEach` as in `CalendarFeedTest.php`: an owner and `CreateOrganisation`; grant Team the way the existing Team-feature tests in `tests/Feature/Credentials` do):

```php
it('creates a runner and shows its token once, storing only a hash', function (): void {
    ['runner' => $runner, 'token' => $token] = runnerFor($this->organisation);

    expect($token)->toMatch('/^crr_[A-Za-z0-9]{60}$/')
        ->and($runner->token)->toBe(hash('sha256', $token))
        ->and($runner->toArray())->not->toHaveKey('token');
    expect(Tenancy::run($this->organisation, fn () => RunnerTokens::find($token)?->id))->toBe($runner->id);
});

it('never writes the token to the audit trail', function (): void {
    ['runner' => $runner, 'token' => $token] = runnerFor($this->organisation);
    $logged = Tenancy::run($this->organisation, fn () => DB::table('activities')->where('subject_id', $runner->id)->pluck('properties')->implode(' '));

    expect($logged)->not->toContain($token)->not->toContain(hash('sha256', $token));
});

it('needs credentials.manage and the Team plan', function (): void { /* Viewer → AuthorizationException; Free org → ValidationException on 'name' with message 'Automatic rotation is part of the Team plan.' */ });

it('refuses a second runner with the same name', function (): void { /* ValidationException on 'name' */ });

it('stops finding a revoked runner, or one from another organisation', function (): void {
    // revoke → RunnerTokens::find($token) is null inside the organisation
    // a second organisation: RunnerTokens::find($token) is null inside it
    // 'crr_short' and '' → null without querying
});
```

Write the two commented tests out in full with the assertions named in the comments.

- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/Credentials/RunnersTest.php` — expected: FAIL, class `CreateRunner` not found.
- [ ] **Step 3: Write the migration, model, factory, actions and `RunnerTokens`** to the interfaces above. Validate `name` as `required|string|max:80` plus `Rule::unique('runners')->where('organisation_id', Tenancy::id())`.
- [ ] **Step 4: Add a runner to `populatedOrganisation()`** through `CreateRunner` (the fixture organisation must be on Team; follow how the fixture already unlocks Team-only rows such as the Slack channel).
- [ ] **Step 5: Run** the file and `tests/Feature/Tenancy/IsolationSuiteTest.php` and `tests/Arch` — expected: PASS.
- [ ] **Step 6: Pint, commit** `feat(credentials): rotation runners with hashed tokens`.

---

### Task 3: Automatic rotation settings on a credential

**Files:**
- Create: migration `create_automatic_rotations_table`, `app/Modules/Credentials/Models/AutomaticRotation.php`, `database/factories/AutomaticRotationFactory.php`
- Create: `app/Modules/Credentials/Enums/RotationSource.php`, `RotationDestination.php`
- Create: `app/Modules/Credentials/Actions/SetAutomaticRotation.php`, `RemoveAutomaticRotation.php`, `RequestRotation.php`
- Modify: `app/Modules/Credentials/Models/Credential.php` (relation), `tests/Pest.php`
- Test: `tests/Feature/Credentials/AutomaticRotationTest.php`

**Interfaces:**
- Consumes: `Runner`, `Feature::AutomaticRotation`.
- Produces:
  - Table `automatic_rotations`: `credential_id` uuid, `tenantForeign('credential_id', 'credentials')` cascade on delete, `unique(organisation_id, credential_id)`; `runner_id` uuid, `tenantForeign('runner_id', 'runners')` restrict; `source` string; `source_settings` jsonb; `destinations` jsonb; `requested_at` timestampTz nullable, comment "someone pressed Rotate now"; `timestampsTz()`.
  - `AutomaticRotation`: `#[Fillable(['credential_id', 'runner_id', 'source', 'source_settings', 'destinations'])]`; casts `source` → `RotationSource`, `source_settings` and `destinations` → `array`, `requested_at` → datetime; relations `credential()`, `runner()`.
  - `Credential::automaticRotation(): HasOne<AutomaticRotation, $this>`.
  - `SetAutomaticRotation::handle(User $actor, Credential $credential, array $data): AutomaticRotation` — creates or replaces the credential's row. Needs `Ability::ManageCredentials` and the feature.
  - `RemoveAutomaticRotation::handle(User $actor, Credential $credential): void` — deletes the row; refuses with a `ValidationException` (`'This credential is being rotated right now. Try again in an hour.'`) while it has a `Claimed` run (the check is added in Task 4; write the action now without it and add the test and check there).
  - `RequestRotation::handle(User $actor, Credential $credential): AutomaticRotation` — gated by the `rotate` policy; sets `requested_at` to now; `ValidationException` when the credential has no automatic rotation or is revoked.
  - `$data` shape and rules for `SetAutomaticRotation`:

```php
[
    'runner_id' => ['required', Rule::exists('runners', 'id')->where('organisation_id', Tenancy::id())->whereNull('revoked_at')],
    'source' => ['required', Rule::enum(RotationSource::class)],
    'source_settings.project_id' => ['required', 'string', 'max:100', new NotASecret],
    'source_settings.service_account_id' => ['required', 'string', 'max:100', new NotASecret],
    'source_settings.key_lifetime_days' => ['nullable', 'integer', 'min:1', 'max:365'],
    'destinations' => ['required', 'array', 'min:1', 'max:5'],
    'destinations.*.type' => ['required', Rule::enum(RotationDestination::class)],
    'destinations.*.secret_id' => ['required', 'string', 'max:100', new NotASecret],
    'destinations.*.secret_name' => ['required', 'string', 'max:100', 'regex:/^[A-Z][A-Z0-9_]*$/'],
    'destinations.*.environment_ids' => ['array', 'max:10'],
    'destinations.*.environment_ids.*' => ['string', 'max:100', new NotASecret],
    'destinations.*.redeploy' => ['required', 'boolean'],
]
```

  Extra rules, each a validation error on the named field: the credential must have `rotation_days` (`runner_id`: `'Give this credential a rotation schedule first.'`); the credential must not be revoked; `redeploy` true needs at least one environment id; when the credential has `expires_at`, `key_lifetime_days` is required (`'This credential expires: say how many days each new key lasts.'`). Only the validated keys are stored, so unknown keys are dropped.

- [ ] **Step 1: Write the failing tests.** One helper `automaticRotationData(Runner $runner, array $overrides = []): array` returning the contract's example values. Tests, each asserting the outcome named:
  - `it('switches on automatic rotation for a credential')` — row exists, `source` is `RotationSource::OpenAi`, `destinations.0.secret_name` is `'OPENAI_API_KEY'`.
  - `it('replaces the settings when set again')` — one row, new `project_id`.
  - `it('refuses a runner from another organisation or a revoked runner')` — error on `runner_id`.
  - `it('refuses anything that looks like a key', …)->with([...])` — dataset over `source_settings.project_id`, `destinations.0.secret_id`, `destinations.0.environment_ids.0` each set to `'sk-proj-'.str_repeat('a1B2', 10)`; error on that field; no row.
  - `it('needs a rotation schedule, the Team plan and credentials.manage')`.
  - `it('asks for a key lifetime when the credential expires')`.
  - `it('drops settings it does not know')` — pass `source_settings.api_key => 'x'`; stored array has no `api_key`.
  - `it('lets the owner ask for a rotation now')` — `requested_at` set; a Viewer who isn't the owner gets `AuthorizationException`.
  - `it('removes automatic rotation and keeps the credential')`.
- [ ] **Step 2: Run** the file — expected: FAIL, class `SetAutomaticRotation` not found.
- [ ] **Step 3: Write the migration, enums, model, factory, relation and the three actions.**
- [ ] **Step 4: Add an automatic rotation to `populatedOrganisation()`** through `SetAutomaticRotation`.
- [ ] **Step 5: Run** the file, the isolation suite and `tests/Arch` — expected: PASS.
- [ ] **Step 6: Pint, commit** `feat(credentials): automatic rotation settings per credential`.

---

### Task 4: Rotation runs and claiming due work

**Files:**
- Create: migration `create_rotation_runs_table`, `app/Modules/Credentials/Models/RotationRun.php`, `database/factories/RotationRunFactory.php`, `app/Modules/Credentials/Enums/RotationRunStatus.php`
- Create: `app/Modules/Credentials/Actions/ClaimRotationRuns.php`, `app/Modules/Credentials/Notifications/AutomaticRotationFailed.php`
- Modify: `app/Modules/Credentials/Actions/RemoveAutomaticRotation.php`, `tests/Pest.php`
- Test: `tests/Feature/Credentials/RotationRunClaimsTest.php`

**Interfaces:**
- Consumes: `RunnerTokens::find()`, `AutomaticRotation`, config values.
- Produces:
  - Table `rotation_runs`: `credential_id` (tenantForeign `credentials`, restrict); `runner_id` (tenantForeign `runners`, restrict); `status` string; `claimed_at` timestampTz; `finished_at` timestampTz nullable; `credential_rotation_id` uuid nullable (tenantForeign `credential_rotations`, restrict); `new_key_identifier` string(100) nullable; `new_key_hint` string(8) nullable; `old_keys_expire_at` timestampTz nullable; `deployments` jsonb nullable; `failed_step` string(20) nullable; `failure` text nullable; index `(organisation_id, credential_id, claimed_at)`; and a partial unique index so a credential has at most one open run: `create unique index rotation_runs_one_open on rotation_runs (organisation_id, credential_id) where status = 'claimed'` (raw statement after the table).
  - `RotationRun`: casts `status` → `RotationRunStatus`, the three timestamps, `deployments` → `array`; relations `credential()` (with trashed), `runner()`, `rotation()` (`credential_rotation_id`). Sets `public const null UPDATED_AT = null; public const null CREATED_AT = null;` (`claimed_at` is the creation time).
  - `ClaimRotationRuns::handle(Organisation $organisation, string $token): ?array` — `null` means unauthenticated. Otherwise `array{jobs: list<array>}` in exactly the contract's shape. Inside `Tenancy::run()` and one transaction it:
    1. finds the runner; sets `last_seen_at` to now;
    2. marks every `Claimed` run of this organisation older than `claim_expires_minutes` as `Abandoned` with `finished_at` now and `failure` `'The runner did not report back.'`, and sends `AutomaticRotationFailed` for each;
    3. returns no jobs unless `Entitlements::allows(Feature::AutomaticRotation)`;
    4. selects, with `lockForUpdate()`, up to `max_jobs_per_claim` automatic rotations of **this runner** whose credential is not revoked or deleted and either `requested_at` is set, or `next_rotation_at <= now` and no run of that credential `Failed` or was `Abandoned` within `retry_after_hours`; skipping credentials with a `Claimed` run; oldest `next_rotation_at` first;
    5. creates a `Claimed` run for each, clears `requested_at`, and builds the job.
  - `AutomaticRotationFailed` notification (`via`: `mail`, `database`), constructor `(string $credentialReference, string $credentialName, string $reason, string $url)`; title `"Automatic rotation failed: {reference}"`; body `"{name} was not rotated. {reason} The current key still works. Rotate it yourself, or fix the runner and press Rotate now."`; database payload in the hand-built Filament format used by `CredentialsImported` with `status` `danger`. Sent to the logins of the credential's owner and backup owner (`ReminderRecipients::memberOf()`), or to `ReminderRecipients::admins()` when neither has one. Put the sending in one private method of a small `Support\RotationFailures::notify(RotationRun $run): void` so Task 5 reuses it.

- [ ] **Step 1: Write the failing tests.** Helper `dueAutomatedCredential(array $attributes = [], int $overdueBy = 0): Credential` (factory `overdueBy()`/`dueIn()` plus `SetAutomaticRotation` with the runner from `beforeEach`). Tests:
  - `it('hands a due credential to its runner in the agreed shape')` — assert the whole job array with `toEqual()` against the contract example (run id taken from the database), and `runner.last_seen_at` is set.
  - `it('hands out nothing that is not due yet')`.
  - `it('hands out a credential someone asked to rotate now, once')` — second claim returns no jobs; `requested_at` is null.
  - `it('never hands out the same credential twice while a run is open')`.
  - `it('gives up on a run after an hour and tells the owner')` — travel 61 minutes; run is `Abandoned`; `Notification::assertSentTo($ownerUser, AutomaticRotationFailed::class)`; not offered again until 6 hours have passed, then offered.
  - `it('only hands a runner its own credentials')` — second runner in the same organisation gets no jobs.
  - `it('hands out nothing on the Free plan, for a revoked or deleted credential', …)` — three cases.
  - `it('hands out at most five jobs at a time')`.
  - `it('answers null for an unknown, revoked or foreign token')`.
  - `it('refuses to remove automatic rotation while a run is open')` — the Task 3 message.
- [ ] **Step 2: Run** the file — expected: FAIL, class `ClaimRotationRuns` not found.
- [ ] **Step 3: Write the migration, enum, model, factory, notification, `RotationFailures` and the action;** add the open-run check to `RemoveAutomaticRotation`.
- [ ] **Step 4: Add a run to `populatedOrganisation()`** through `ClaimRotationRuns` (make the fixture's automated credential due first).
- [ ] **Step 5: Run** the file, the isolation suite, `tests/Arch` — expected: PASS.
- [ ] **Step 6: Pint, commit** `feat(credentials): rotation runs and claiming due work`.

---

### Task 5: Reporting a run and recording the rotation

**Files:**
- Create: `app/Modules/Credentials/Actions/ReportRotationRun.php`, `app/Modules/Credentials/Support/RotationWriter.php`
- Modify: `app/Modules/Credentials/Actions/RecordRotation.php` (use `RotationWriter`), `app/Modules/Credentials/Models/CredentialRotation.php` (relation `run()`: `HasOne<RotationRun, $this>` on `credential_rotation_id`)
- Test: `tests/Feature/Credentials/RotationRunReportsTest.php`; existing `tests/Feature/Credentials/RotationsTest.php` must stay green untouched.

**Interfaces:**
- Consumes: `RotationRun`, `RunnerTokens`, `RotationFailures::notify()`.
- Produces:
  - `RotationWriter::write(Credential $locked, ?User $rotatedBy, CarbonInterface $rotatedAt, ?CarbonInterface $expiresAt, ?string $notes): CredentialRotation` — the part of `RecordRotation` that creates the `credential_rotations` row (`due_at` from `$locked->next_rotation_at`) and force-fills `last_rotated_at` and `expires_at`. Must be called inside a transaction with the credential locked. `RecordRotation` keeps its authorisation, validation and evidence handling and calls this.
  - `ReportRotationRun::handle(Organisation $organisation, string $token, array $data): ?array` — `null` means unauthenticated; otherwise `array{status: int, body: array}` with status 200, 403, 404 or 409 as the contract says; throws `ValidationException` for 422. Validation:

```php
'run' => ['required', 'uuid'],
'outcome' => ['required', Rule::in(['succeeded', 'failed'])],
// succeeded
'rotated_at' => ['required_if:outcome,succeeded', 'date'],
'new_key.id' => ['required_if:outcome,succeeded', 'string', 'max:100', new NotASecret],
'new_key.hint' => ['nullable', 'string', 'max:'.config('credentials.hint_max_length'), new NotASecret],
'new_key.expires_at' => ['nullable', 'date', 'after:rotated_at'],
'old_keys_expire_at' => ['nullable', 'date'],
'deployments' => ['array', 'max:20'],
'deployments.*' => ['string', 'max:100', new NotASecret],
// failed
'step' => ['required_if:outcome,failed', Rule::in(['mint', 'push', 'deploy', 'retire'])],
'message' => ['required_if:outcome,failed', 'string', 'max:500', new NotASecret],
```

  Behaviour, in one transaction with the run and its credential locked:
  - The run must belong to this runner, else 404.
  - **Succeeded**, run is `Claimed` or `Abandoned`: `rotated_at` must be no earlier than `claimed_at` minus `clock_skew_minutes` and no later than now, and later than the credential's `last_rotated_at` (422 on `rotated_at` otherwise). If the credential is revoked: mark the run `Failed` with failure `'The credential was revoked before the run finished.'` and answer 422 on `run`. If the credential has `expires_at` and `new_key.expires_at` is missing: 422 on `new_key.expires_at`. Otherwise call `RotationWriter::write()` with `rotatedBy` null and notes exactly `Rotated automatically by the runner "{runner name}".`, set the credential's `hint` to `new_key.hint` when given, fill the run's evidence columns, set `status` `Succeeded`, `finished_at` now, `credential_rotation_id`. Answer 200.
  - **Failed**, run is `Claimed`: store `failed_step`, `failure`, `status` `Failed`, `finished_at`; call `RotationFailures::notify()`. Answer 200.
  - Run already finished with the same outcome: 200, nothing written. Any other combination: 409.
  - `Entitlements::allows(Feature::AutomaticRotation)` false: 403 before anything else (after authentication).

- [ ] **Step 1: Write the failing tests.** Helper `claimedRun(): RotationRun` (due credential → `ClaimRotationRuns`) and `successReport(RotationRun $run, array $overrides = []): array` with the contract's values and `rotated_at` now. Tests:
  - `it('records a rotation from a successful report')` — one `credential_rotations` row with `rotated_by_id` null, `due_at` the old `next_rotation_at`, notes `Rotated automatically by the runner "GitHub".`; credential `last_rotated_at` moved, `next_rotation_at` is `rotation_days` later, `hint` is `'AB12'`; run `Succeeded` with `new_key_identifier` `'key_abc'`, `deployments` `['dep-1']`, linked rotation.
  - `it('refuses a report that carries something that looks like a key', …)->with([...])` — dataset: `new_key.id`, `new_key.hint` (9 chars), `deployments.0`, and a failed report's `message`, each carrying `'sk-proj-'.str_repeat('a1B2', 10)`; `ValidationException`; run still `Claimed`; `DB::table('activities')` holds no row containing the value.
  - `it('records nothing new when the same report arrives twice')` — second call 200; still one rotation.
  - `it('accepts a late success for a run it had given up on')` — travel 61 minutes, claim again as another poll (run becomes `Abandoned`), report success with `rotated_at` inside the window → 200, run `Succeeded`.
  - `it('answers 409 when a finished run is reported with the other outcome')`.
  - `it('stores why a run failed and tells the owner')` — `failed_step` `'push'`; notification sent; credential's `last_rotated_at` unchanged.
  - `it('refuses a rotation time before the claim or in the future')`.
  - `it('refuses a success for a credential revoked meanwhile')` — run `Failed`, no rotation row.
  - `it('asks for the new expiry when the credential expires')`.
  - `it('answers 404 for another runner\'s run and null for a bad token')`.
  - `it('answers 403 once the organisation is back on Free')`.
- [ ] **Step 2: Run** the file — expected: FAIL, class `ReportRotationRun` not found.
- [ ] **Step 3: Extract `RotationWriter`** from `RecordRotation` and run `tests/Feature/Credentials/RotationsTest.php` and `RotationEvidenceTest.php` — expected: PASS with no test changes.
- [ ] **Step 4: Write `ReportRotationRun`** and the `run()` relation.
- [ ] **Step 5: Run** the new file — expected: PASS. Pint, commit `feat(credentials): runners report rotations as evidence`.

---

### Task 6: The two HTTP endpoints

**Files:**
- Create: `app/Http/Controllers/RunnerClaimsController.php`, `app/Http/Controllers/RunnerReportsController.php`
- Modify: `routes/web.php`, `app/Providers/AppServiceProvider.php` (rate limiter `runner-api`, Nightwatch redaction), `.ai/rules/http-routing.md`
- Test: `tests/Feature/Credentials/RunnerApiTest.php`

**Interfaces:**
- Consumes: `ClaimRotationRuns::handle()`, `ReportRotationRun::handle()`.
- Produces: routes `runner.claims` and `runner.reports` as named in "Names used throughout", both `->withoutMiddleware('web')->middleware('throttle:runner-api')`, `{organisation}` constrained to `[a-z0-9-]{3,40}`.

Decisions:
- Controllers are invokable and thin, like `CalendarFeedController`: find the organisation by `slug` or `previous_slugs`; read the token with `$request->bearerToken()`; call the action; turn `null` (or an unknown organisation, or a missing token) into `response()->json(['message' => 'Unauthenticated.'], 401)`. Every response carries `Cache-Control: no-store`.
- Limiter `runner-api`: `Limit::perMinute(120)->by('runner-ip:'.$request->ip())` and `Limit::perMinute(30)->by('runner-token:'.hash('sha256', (string) $request->bearerToken()))`.
- `api` is already in `ReservedSlugs`; confirm `AppPanelTenancyTest` stays green and add no slug.
- Nightwatch must never record the `Authorization` header of these requests: check what the installed `laravel/nightwatch` version redacts by default (`search-docs`, then the package source) and add an explicit redaction beside the Telegram one if `Authorization` isn't already covered. Note what you found in the commit message.
- Add to `.ai/rules/http-routing.md` under "Routes outside the panels": one bullet describing the two routes, that they run without `web`, authenticate with a bearer token whose hash is stored, answer 401 for anything wrong with slug or token, are throttled per address and per token, and never accept or return a secret value.

- [ ] **Step 1: Write the failing tests** (real HTTP through `$this->postJson()` with `withToken()`):
  - `it('gives a runner its due work over HTTP')` — 200, `jobs.0.credential.reference` matches, header `Cache-Control` contains `no-store`.
  - `it('records a report over HTTP')` — 200 `{"recorded":true}`.
  - `it('answers 401 for a missing, malformed, revoked or unknown token and for an unknown organisation', …)` — five cases, identical body.
  - `it('answers 401 when one organisation\'s token is used on another organisation')`.
  - `it('answers 404 when a run from another organisation is reported')`.
  - `it('answers 422 with field errors for a bad report')` — `assertJsonValidationErrors(['outcome'])`.
  - `it('keeps working under a renamed slug')` — rename through `RenameOrganisationSlug`, call the old slug → 200.
  - `it('needs no session or CSRF token and sets no cookie')` — response has no `Set-Cookie`.
  - `it('throttles a token that polls too fast')` — 31st claim in a minute is 429.
- [ ] **Step 2: Run** the file — expected: FAIL, 404 route not found.
- [ ] **Step 3: Write the routes, controllers, limiter and rule text.**
- [ ] **Step 4: Run** the file plus `tests/Feature/Filament` tests whose name contains `AppPanelTenancy` — expected: PASS.
- [ ] **Step 5: Pint, commit** `feat(credentials): runner API endpoints`.

---

### Task 7: Reminders for automatically rotated credentials

**Files:**
- Modify: `app/Modules/Credentials/Actions/ClaimDueReminders.php`
- Test: `tests/Feature/Credentials/RemindersTest.php` (add cases)

Decision: a credential with automatic rotation gets **no upcoming reminders** (the runner will do it) and **keeps every overdue reminder** (that is the alarm when the runner is silent or failing). Skip the step when `$step->kind === ReminderKind::Upcoming` and the credential has an `automaticRotation`; eager-load the relation in the existing query.

- [ ] **Step 1: Write the failing tests:**

```php
it('sends no upcoming reminder for a credential a runner rotates', function (): void { /* due in 7 days + automatic rotation → handle() claims nothing */ });
it('still sends overdue reminders for a credential a runner rotates', function (): void { /* overdue by 3 days + automatic rotation → a reminder with kind overdue is claimed */ });
```

- [ ] **Step 2: Run** them — expected: the first FAILS (a reminder is claimed).
- [ ] **Step 3: Make the change.**
- [ ] **Step 4: Run** `RemindersTest.php`, `EscalationTest.php`, `ReminderPlannerTest.php` — expected: PASS.
- [ ] **Step 5: Pint, commit** `feat(credentials): no upcoming reminders for automatically rotated credentials`.

---

### Task 8: Screens

**Files:**
- Create: `app/Filament/App/Pages/RotationRunners.php`
- Modify: `app/Filament/App/Resources/Credentials/Pages/ViewCredential.php`, `Schemas/CredentialInfolist.php`, `RelationManagers/RotationsRelationManager.php`, `Tables/CredentialsTable.php`
- Modify: `app/Modules/Credentials/Support/RotationEvidenceReport.php`
- Test: `tests/Feature/Filament/RotationRunnersPageTest.php`, `tests/Feature/Filament/AutomaticRotationScreenTest.php`, add a case to `tests/Feature/Credentials/RotationEvidenceReportTest.php`

Read `.ai/rules/filament.md` and `.ai/rules/brand.md` first, and model the page on `App\Filament\App\Pages\CalendarFeed` (token shown once in a public Livewire property, then gone).

**Decisions and exact copy:**
- **Rotation runners page** — navigation group `Credentials`, sort `15`, title `Rotation runners`, visible to members with `credentials.manage`; on a plan without the feature it shows `Automatic rotation is part of the Team plan.` and no actions.
  - Intro: `A rotation runner is a small program you run in your own GitHub account or Laravel app. It swaps keys when they are due and tells ComplyRobin when it is done. Your keys and admin tokens stay with you: ComplyRobin never sees them.`
  - Table: Name · Last checked in (`3 hours ago`, or `Never`) · Status badge: `Active` (success), `Silent` (warning, no check-in for 48 hours or never after a day), `Revoked` (gray).
  - Action `Add runner` (form: Name). After it runs, show once: `Copy this token now. For your security it is shown only once.` then the token (mono, copyable), then `Organisation: {slug}` (copyable).
  - Row action `Revoke` with confirmation `This runner stops working straight away. Credentials it rotates will become overdue until you move them to another runner.` → `RevokeRunner`.
- **Credential view** — a section `Automatic rotation`:
  - Off: `Off. You record rotations yourself.` and the action `Set up automatic rotation` (hidden without `credentials.manage`, the feature, or `rotation_days`).
  - On: `Runner`, `Makes the key` (`OpenAI · project {project_id}`), `Puts it in` (one line per destination: `Laravel Cloud · {secret_name}` plus ` · redeploys {n} environments` when it does), `Last run` (`Succeeded 17 Nov 2026`, `Failed 17 Nov 2026: {failure}`, `Running now` or `Not run yet`).
  - Actions: `Change` (same form) → `SetAutomaticRotation`; `Switch off` → `RemoveAutomaticRotation`; `Rotate now` (confirmation `The runner will swap this key the next time it checks in.`) → `RequestRotation`.
  - Form fields: Runner (select of active runners) · OpenAI project ID · OpenAI service account ID (helper: `Use a service account that exists only for this credential. The runner retires every other key it has.`) · New keys last (days, optional) · repeater `Where the key is used`: Laravel Cloud secret ID, Secret name, Environment IDs (tags input), `Redeploy after the change` (toggle, default on). No field anywhere accepts a key or token.
- **Rotations list** — the `By` column shows the user's name, or `Automatic rotation` when the rotation has a run.
- **Credentials table** — an icon column `Automatic` (bolt icon, tooltip `Rotated by a runner`), toggleable, hidden by default.
- **Evidence report** — `rotated_by` is the user's name, or `Automatic rotation ({runner name})` when the rotation has a run; eager-load `run.runner`.

- [ ] **Step 1: Write the failing tests** with `Livewire::test()` inside the organisation as the existing Filament tests do:
  - Runners page: adding a runner shows a token matching `/^crr_/` once and creates the row; a second render no longer shows it; revoking sets `revoked_at`; a Viewer can't open the page; on Free the upgrade sentence shows and `Add runner` is hidden.
  - Credential view: `Set up automatic rotation` saves through the action and the section then shows `OpenAI · project proj_abc`; `Rotate now` sets `requested_at`; `Switch off` removes the row; the set-up action is hidden for a Viewer and for a credential without a schedule.
  - Evidence report: a rotation written by `ReportRotationRun` appears with `Automatic rotation (GitHub)` in the CSV row.
- [ ] **Step 2: Run** the three files — expected: FAIL, page class not found.
- [ ] **Step 3: Build the page, the section and actions, the two columns and the report change.**
- [ ] **Step 4: Run** the three files and `tests/Feature/Filament` — expected: PASS.
- [ ] **Step 5: Pint, commit** `feat(credentials): rotation runners page and automatic rotation on a credential`.

---

### Task 9: Whole-change checks

- [ ] **Step 1:** `composer dump-autoload && composer lint && composer analyse && composer refactor` — expected: clean. Fix what they report.
- [ ] **Step 2:** `composer test` — expected: all green. This is the one full-suite run of the plan.
- [ ] **Step 3:** In the spec's Phase 2b entry add `**Status (date): built.**` with one line per Review Focus item naming the test that proves it. Add a case to `tests/Feature/Tenancy` `LongRunningProcessTest`/`OctaneTest` only if this plan introduced per-request state (it should not have: say so in the commit message).
- [ ] **Step 4: Commit** `docs(spec): automatic rotation runner API is built`.

---

## Not in this plan

- **The runner itself (Plan 2).** A PHP CLI in its own public repository, packaged as a GitHub Action and as a Laravel package: the OpenAI and Laravel Cloud clients, sealing with libsodium, redeploying, retiring old keys, and calling the two endpoints above. Before it is written, test on a real Laravel Cloud environment whether an API-started deployment also ships new commits from the branch.
- Other sources and destinations (Forge, GitHub Actions secrets, AWS), assisted mode for vendors that can't mint keys, read-only discovery, and hosted rotation (Design A).
- Billing for the feature beyond the existing Team gate.
