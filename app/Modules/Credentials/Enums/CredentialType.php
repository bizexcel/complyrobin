<?php

namespace App\Modules\Credentials\Enums;

enum CredentialType: string
{
    case ApiToken = 'api_token';
    case OauthClient = 'oauth_client';
    case SshKey = 'ssh_key';
    case Certificate = 'certificate';
    case Password = 'password';
    case WebhookSecret = 'webhook_secret';
    case ServiceAccount = 'service_account';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::ApiToken => 'API token',
            self::OauthClient => 'OAuth client',
            self::SshKey => 'SSH key',
            self::Certificate => 'Certificate',
            self::Password => 'Password',
            self::WebhookSecret => 'Webhook secret',
            self::ServiceAccount => 'Service account',
            self::Other => 'Other',
        };
    }
}
