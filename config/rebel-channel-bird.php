<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Bird credentials
    |--------------------------------------------------------------------------
    | From the Bird (formerly MessageBird) dashboard. The `access_key` is your live
    | API access key (Developers → API access). The `originator` is the sender id or
    | number shown to the recipient (an alphanumeric sender id is max 11 chars, or an
    | approved phone number). `access_key` is required for the provider to register.
    |
    | `workspace_id` is reserved for Bird's newer Channels API and is not required by
    | the legacy Verify/Messaging endpoints this package uses; keep it for forward
    | compatibility / your own bridges.
    */
    'access_key' => env('BIRD_ACCESS_KEY'),
    'workspace_id' => env('BIRD_WORKSPACE_ID'),
    'originator' => env('BIRD_ORIGINATOR', 'Code'),

    /*
    |--------------------------------------------------------------------------
    | Channels & registration
    |--------------------------------------------------------------------------
    | Which Rebel channels this provider may handle, and whether to auto-register it
    | into the Rebel Channels provider registry on boot (when credentials are present).
    | Bird Verify is an SMS-first OTP product, so `sms` is the default channel.
    */
    'channels' => ['sms'],
    'register_provider' => env('REBEL_BIRD_REGISTER', true),

    /*
    |--------------------------------------------------------------------------
    | Delivery status webhook
    |--------------------------------------------------------------------------
    | A POST endpoint that receives Bird delivery-status webhooks and records a Rebel
    | audit event (delivered / undelivered / dispatched + cost), so the admin panel's
    | Channel Performance shows real numbers.
    |
    | Point Bird at it by configuring a webhook subscription / status report URL in the
    | Bird dashboard to:
    |
    |     https://<your-host>/rebel/bird/status
    |
    | The route carries NO auth middleware (Bird posts server-to-server); instead, when
    | `validate_signature` is true, the `MessageBird-Signature` header is verified
    | (HMAC-SHA256 of timestamp + URL + body hash, keyed with your webhook `signing_key`)
    | before the callback is recorded. Disable `enabled` to drop the route entirely.
    */
    'webhook' => [
        'enabled' => env('REBEL_BIRD_WEBHOOK', true),
        'validate_signature' => env('REBEL_BIRD_WEBHOOK_VALIDATE', true),
        'signing_key' => env('BIRD_WEBHOOK_SIGNING_KEY'),
        'path' => env('REBEL_BIRD_WEBHOOK_PATH', 'rebel/bird/status'),
    ],

];
