<?php

namespace App\Policies;

use App\Models\Entry;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class EntryPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Entry $entry): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Entry $entry): bool
    {
        return true;
    }

    public function delete(User $user, Entry $entry): bool
    {
        return true;
    }

    public function restore(User $user, Entry $entry): bool
    {
        return true;
    }

    public function forceDelete(User $user, Entry $entry): bool
    {
        return true;
    }
}
