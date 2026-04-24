<?php

namespace App\Models;

use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
  use HasApiTokens, HasFactory, Notifiable;

  // Use the correct database connection
  protected $connection = 'users_main';

  // Set primary key
  protected $primaryKey = 'id';

  // If user_id is not auto-increment
  public $incrementing = false;

  // Match your DB column type
  protected $keyType = 'bigint';

  protected $fillable = [
    'id',
    'user_id',
    'username',
    'email',
    'email_verified_at',
    'password',
    'otp_code',
    'otp_verified_at',
    'remember_token',
    'account_status',
    'last_successfull_login',
    'fullname',
    'school_code',
    'gs_access_status',
    'password_update_by',
  ];

  protected $hidden = [
    'password',
    'remember_token',
  ];

  protected function casts(): array
  {
    return [
      'email_verified_at' => 'datetime',
      'otp_verified_at' => 'datetime',
      'last_successfull_login' => 'datetime',
      'password' => 'hashed',
    ];
  }
}
