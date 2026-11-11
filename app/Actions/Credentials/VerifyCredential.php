<?php

namespace App\Actions\Credentials;

use App\Models\Credential;
use App\Services\Providers\ProviderException;
use App\Services\Providers\ProviderRegistry;

class VerifyCredential
{
    public function __construct(private ProviderRegistry $providers)
    {
        //
    }

    /**
     * Check the token with its provider and record the result on the credential.
     */
    public function handle(Credential $credential): bool
    {
        try {
            $account = $this->providers->account($credential);
        } catch (ProviderException $exception) {
            $credential->forceFill([
                'last_error' => $exception->getMessage(),
                'last_verified_at' => null,
            ])->save();

            return false;
        }

        $credential->forceFill([
            'account_name' => $account->name,
            'scopes' => $account->scopes,
            'last_verified_at' => now(),
            'last_error' => null,
        ])->save();

        return true;
    }
}
