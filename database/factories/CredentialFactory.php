<?php

namespace Database\Factories;

use App\Core\Numbering\Numbering;
use App\Modules\Credentials\Enums\CredentialType;
use App\Modules\Credentials\Enums\Environment;
use App\Modules\Credentials\Models\Application;
use App\Modules\Credentials\Models\Credential;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Credential>
 */
class CredentialFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'application_id' => Application::factory(),
            'name' => fake()->randomElement(['Deploy token', 'API key', 'Webhook signing secret', 'CI token']),
            'type' => CredentialType::ApiToken,
            'environment' => Environment::Production,
            'rotation_days' => 90,
            'last_rotated_at' => now()->subDays(10),
        ];
    }

    /**
     * Due in this many days (0 is today).
     */
    public function dueIn(int $days): static
    {
        return $this->state(fn (): array => ['rotation_days' => 90, 'last_rotated_at' => now()->addDays($days - 90), 'expires_at' => null]);
    }

    public function overdueBy(int $days): static
    {
        return $this->dueIn(-$days);
    }

    /**
     * Created with a reference, as CreateCredential gives one.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (Credential $credential): void {
            $credential->reference ??= app(Numbering::class)->next('credential', 'CRD');
        });
    }
}
