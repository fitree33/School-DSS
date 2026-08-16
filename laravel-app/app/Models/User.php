<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'teacher_code',
        'role_id',
        'department_id',
        'phone',
        'is_active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_login_at' => 'datetime',
        'is_active' => 'boolean',
        'password' => 'hashed',
    ];

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function createdProjects()
    {
        return $this->hasMany(Project::class);
    }

    public function projectAccess()
    {
        return $this->hasMany(ProjectAccess::class);
    }

    public function notifications()
    {
        return $this->hasMany(ProjectNotification::class);
    }

    public function hasPermission(string $permission): bool
    {
        if ($this->is_active === false || ! $this->role_id) {
            return false;
        }

        return $this->role()
            ->whereHas('permissions', fn ($query) => $query->where('code', $permission))
            ->exists();
    }

    public function hasRole(string $roleCode): bool
    {
        return $this->is_active !== false
            && $this->role?->code === $roleCode;
    }
}
