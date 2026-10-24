<?php

namespace App\Console\Commands;

use App\Support\CredentialsEncrypter;
use Illuminate\Console\Command;

class GenerateCredentialsKey extends Command
{
    protected $signature = 'credentials:key';

    protected $description = 'Print a new key for CREDENTIALS_KEY (marketplace credentials)';

    public function handle(): int
    {
        // Printed, not written to .env: in production the key belongs in the deployment secrets.
        $this->line(CredentialsEncrypter::generate(config('credentials.cipher')));

        return self::SUCCESS;
    }
}
