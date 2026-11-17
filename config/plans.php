<?php

/*
 * What each plan allows. A limit of null means unlimited. Prices live with billing; these are the rules the app
 * enforces. Going over a limit after a downgrade never deletes anything: existing records stay usable, but no new ones
 * can be added until the organisation is back under the limit or upgrades.
 */

$paidFeatures = ['chat_channels', 'whatsapp', 'escalation', 'evidence_report', 'audit_export', 'spreadsheet_import', 'automatic_rotation'];

return [
    'free' => [
        'limits' => ['members' => 3, 'credentials' => 10],
        'features' => [],
    ],

    'team' => [
        // A flat price includes 10 members; extra members are bought as seats with billing.
        'limits' => ['members' => 10, 'credentials' => null],
        'features' => $paidFeatures,
    ],

    'enterprise' => [
        'limits' => ['members' => null, 'credentials' => null],
        'features' => $paidFeatures,
    ],
];
