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
