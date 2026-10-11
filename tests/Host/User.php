<?php

namespace Ombabush\StoryStudio\Tests\Host;

use Illuminate\Foundation\Auth\User as Authenticatable;

/** The bare host's user: Laravel's own, nothing the package could lean on by accident. */
class User extends Authenticatable
{
    protected $table = 'users';

    protected $guarded = [];
}
