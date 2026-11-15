---
paths:
  - app/**/Actions/**
---

# Actions

## One use case per class
Name actions verb-noun (`SubmitForApproval`, `RaiseNonConformity`, `ScoreRisk`) with a single public `handle()` method. Filament resources, jobs and controllers call actions; actions never import `Filament\*` (enforced by `ArchTest`).

## Shape of a write
Authorise first, then do the work inside `DB::transaction()`, then record the activity log entry. Return the model or a small data object.

## Never trust posted ids
Every id received from a request is resolved through a tenant-scoped query (the model's global scope) or validated with `Rule::exists(...)->where('organisation_id', Tenancy::id())`. Approvals additionally check the approver is in the organisation and is not the requester.

## Exports are formula-safe
Every CSV people download is written with `App\Core\Files\SafeCsv`, which puts an apostrophe before any cell starting with `=`, `+`, `-`, `@`, a tab or a carriage return, so a spreadsheet never runs a value as a formula. Generated files (reports) are kept as the organisation's media (`Organisation` collection `rotation-evidence`), under its own folder, and downloaded through short-lived signed links. One export of a kind runs at a time per organisation (a cache lock whose owner the job releases, also in `failed()`).
