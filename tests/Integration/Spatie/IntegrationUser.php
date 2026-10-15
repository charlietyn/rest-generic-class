<?php

namespace Ronu\RestGenericClass\Tests\Integration\Spatie;

use Illuminate\Foundation\Auth\User;
use Ronu\RestGenericClass\Core\Traits\HasReadableUserPermissions;
use Spatie\Permission\Traits\HasRoles;

/** A user model composed as in the generated applications: Spatie's HasRoles plus Ronu's readers. */
class IntegrationUser extends User
{
    use HasRoles;
    use HasReadableUserPermissions;

    protected $table = 'users';
    protected $guarded = [];
    public $timestamps = false;
    protected $guard_name = 'web';
}
