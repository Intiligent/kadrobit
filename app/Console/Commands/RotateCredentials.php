<?php

namespace App\Console\Commands;

use App\Models\MarketplaceAccount;
use Illuminate\Console\Command;

/**
 * Re-encrypts every account's credentials with the current CREDENTIALS_KEY.
 * Old values are read with CREDENTIALS_PREVIOUS_KEYS; remove those once this has run.
 */
class RotateCredentials extends Command
{
    protected $signature = 'credentials:rotate';

    protected $description = 'Re-encrypt marketplace credentials with the current CREDENTIALS_KEY';

    public function handle(): int
    {
        $encrypter = app('credentials.encrypter');
        $count = 0;

        MarketplaceAccount::query()->whereNotNull('credentials')->chunkById(200, function ($accounts) use ($encrypter, &$count) {
            foreach ($accounts as $account) {
                // A plain update: the model would see the decrypted value as unchanged and skip the save.
                MarketplaceAccount::query()->whereKey($account->id)->update([
                    'credentials' => $encrypter->encryptString(json_encode($account->credentials)),
                ]);
                $count++;
            }
        });

        $this->info("Re-encrypted {$count} accounts.");

        return self::SUCCESS;
    }
}
