<?php

namespace App\Core\Tenancy;

/**
 * The organisation that was current before each running job, so it can be put back when the job ends. Jobs can run
 * inside one another (a synchronous dispatch from within a job), hence a stack.
 */
final class JobTenancyStack
{
    /** @var list<string|null> */
    private array $previous = [];

    public function push(?string $organisationId): void
    {
        $this->previous[] = $organisationId;
    }

    public function pop(): ?string
    {
        return array_pop($this->previous);
    }
}
