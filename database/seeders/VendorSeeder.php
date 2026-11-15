<?php

namespace Database\Seeders;

use App\Modules\Credentials\Enums\VendorCategory;
use App\Modules\Credentials\Models\Vendor;
use Illuminate\Database\Seeder;

/**
 * The vendor catalogue. Idempotent: upserts by slug on every deploy (as the migrator). A rotation_url is only set
 * where it is the known page for creating and revoking keys; otherwise people start from the website.
 */
class VendorSeeder extends Seeder
{
    /**
     * @var list<array{slug: string, name: string, category: VendorCategory, website_url: string, rotation_url: string|null, rotation_days: int}>
     */
    public const array VENDORS = [
        ['slug' => 'laravel-cloud', 'name' => 'Laravel Cloud', 'category' => VendorCategory::Hosting, 'website_url' => 'https://cloud.laravel.com', 'rotation_url' => null, 'rotation_days' => 90],
        ['slug' => 'github', 'name' => 'GitHub', 'category' => VendorCategory::SourceControl, 'website_url' => 'https://github.com', 'rotation_url' => 'https://github.com/settings/tokens', 'rotation_days' => 90],
        ['slug' => 'aws', 'name' => 'Amazon Web Services', 'category' => VendorCategory::Cloud, 'website_url' => 'https://aws.amazon.com', 'rotation_url' => 'https://console.aws.amazon.com/iam/', 'rotation_days' => 90],
        ['slug' => 'google-cloud', 'name' => 'Google Cloud / Workspace', 'category' => VendorCategory::Cloud, 'website_url' => 'https://cloud.google.com', 'rotation_url' => 'https://console.cloud.google.com/apis/credentials', 'rotation_days' => 90],
        ['slug' => 'microsoft-365', 'name' => 'Microsoft 365 / Entra ID', 'category' => VendorCategory::Productivity, 'website_url' => 'https://www.microsoft.com/microsoft-365', 'rotation_url' => 'https://entra.microsoft.com', 'rotation_days' => 180],
        ['slug' => 'stripe', 'name' => 'Stripe', 'category' => VendorCategory::Payments, 'website_url' => 'https://stripe.com', 'rotation_url' => 'https://dashboard.stripe.com/apikeys', 'rotation_days' => 90],
        ['slug' => 'airwallex', 'name' => 'Airwallex', 'category' => VendorCategory::Payments, 'website_url' => 'https://www.airwallex.com', 'rotation_url' => null, 'rotation_days' => 90],
        ['slug' => 'postmark', 'name' => 'Postmark', 'category' => VendorCategory::Email, 'website_url' => 'https://postmarkapp.com', 'rotation_url' => null, 'rotation_days' => 180],
        ['slug' => 'resend', 'name' => 'Resend', 'category' => VendorCategory::Email, 'website_url' => 'https://resend.com', 'rotation_url' => 'https://resend.com/api-keys', 'rotation_days' => 180],
        ['slug' => 'slack', 'name' => 'Slack', 'category' => VendorCategory::Communication, 'website_url' => 'https://slack.com', 'rotation_url' => 'https://api.slack.com/apps', 'rotation_days' => 180],
        ['slug' => 'cloudflare', 'name' => 'Cloudflare', 'category' => VendorCategory::Security, 'website_url' => 'https://www.cloudflare.com', 'rotation_url' => 'https://dash.cloudflare.com/profile/api-tokens', 'rotation_days' => 90],
        ['slug' => 'openai', 'name' => 'OpenAI', 'category' => VendorCategory::Ai, 'website_url' => 'https://openai.com', 'rotation_url' => 'https://platform.openai.com/api-keys', 'rotation_days' => 90],
        ['slug' => 'anthropic', 'name' => 'Anthropic', 'category' => VendorCategory::Ai, 'website_url' => 'https://www.anthropic.com', 'rotation_url' => 'https://console.anthropic.com/settings/keys', 'rotation_days' => 90],
        ['slug' => 'digitalocean', 'name' => 'DigitalOcean', 'category' => VendorCategory::Cloud, 'website_url' => 'https://www.digitalocean.com', 'rotation_url' => 'https://cloud.digitalocean.com/account/api/tokens', 'rotation_days' => 90],
    ];

    public function run(): void
    {
        foreach (self::VENDORS as $vendor) {
            Vendor::query()->updateOrCreate(['slug' => $vendor['slug']], $vendor);
        }
    }
}
