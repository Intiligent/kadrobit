<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// The user's own channel: everything the server tells a browser about the user's data (App\Events\EntityChanged).
Broadcast::channel('users.{ulid}', fn (User $user, string $ulid) => $user->ulid === $ulid);
