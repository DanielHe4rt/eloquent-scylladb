<?php

namespace DanielHe4rt\Scylloquent\Fixtures\Models;

use DanielHe4rt\Scylloquent\Eloquent\Model;
use Illuminate\Auth\Authenticatable;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;

class User extends Model implements AuthenticatableContract, CanResetPasswordContract
{
    use Authenticatable, CanResetPassword;

    protected $dates = ['birthday'];

    protected static $unguarded = true;

    public function getDateFormat()
    {
        return 'l jS \of F Y h:i:s A';
    }
}
