<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\OrgDocument;
use App\Models\User;

class OrgDocumentPolicy
{
    use HandlesOrgPermissions;

    public function viewAny(User $user, Organization $organization): bool
    {
        return $this->isOrgMember($user, $organization);
    }

    public function view(User $user, OrgDocument $orgDocument): bool
    {
        return $this->isOrgMember($user, $orgDocument->organization);
    }

    public function create(User $user, Organization $organization): bool
    {
        return $this->isOrgAdmin($user, $organization);
    }

    public function update(User $user, OrgDocument $orgDocument): bool
    {
        return $this->isOrgAdmin($user, $orgDocument->organization);
    }

    public function delete(User $user, OrgDocument $orgDocument): bool
    {
        return $this->isOrgAdmin($user, $orgDocument->organization);
    }
}
