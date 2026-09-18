<?php

declare(strict_types=1);

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'is_admin'])]
#[Hidden(['password', 'remember_token'])]
/**
 * Represents an API user that can authenticate through Laravel Passport.
 */
class User extends Authenticatable implements OAuthenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUlids, Notifiable;

    /**
     * Mirror the column default so a newly created model never reports null.
     *
     * Without this, `is_admin` is absent from a model that was just created
     * (the default is applied by the database, not by Eloquent) and every
     * authorization check has to guard against null.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_admin' => false,
    ];

    /**
     * Get the orders this user created.
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'created_by');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
        ];
    }
}
