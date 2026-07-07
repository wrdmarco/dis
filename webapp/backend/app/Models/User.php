<?php

namespace App\Models;

use App\Models\Concerns\UsesUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

final class User extends Authenticatable
{
    use HasApiTokens;
    use HasFactory;
    use Notifiable;
    use SoftDeletes;
    use UsesUlids;

    protected $fillable = [
        'name',
        'first_name',
        'last_name',
        'email',
        'password',
        'phone_number',
        'home_city',
        'home_region',
        'home_country',
        'home_latitude',
        'home_longitude',
        'home_geocoded_at',
        'home_geocode_source',
        'account_status',
        'push_enabled',
        'max_operator_devices',
        'mail_preferences',
        'two_factor_enabled',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_confirmed_at',
        'last_login_at',
        'failed_login_attempts',
        'login_locked_until',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'immutable_datetime',
            'push_enabled' => 'boolean',
            'max_operator_devices' => 'integer',
            'home_latitude' => 'decimal:2',
            'home_longitude' => 'decimal:2',
            'home_geocoded_at' => 'immutable_datetime',
            'mail_preferences' => 'array',
            'two_factor_enabled' => 'boolean',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'immutable_datetime',
            'last_login_at' => 'immutable_datetime',
            'failed_login_attempts' => 'integer',
            'login_locked_until' => 'immutable_datetime',
            'password' => 'hashed',
        ];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)->withPivot(['assigned_by', 'created_at']);
    }

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class)->withPivot(['assigned_by', 'created_at']);
    }

    public function statuses(): HasMany
    {
        return $this->hasMany(AvailabilityStatus::class);
    }

    public function certifications(): HasMany
    {
        return $this->hasMany(UserCertification::class);
    }

    public function assetAssignments(): HasMany
    {
        return $this->hasMany(AssetAssignment::class);
    }

    public function fcmTokens(): HasMany
    {
        return $this->hasMany(FcmToken::class);
    }

    public function vacations(): HasMany
    {
        return $this->hasMany(UserVacation::class);
    }

    public function hasPermission(string $permission): bool
    {
        $query = $this->roles()
            ->whereHas('permissions', fn ($query) => $query->where('permissions.name', $permission));

        match ($this->currentClientType()) {
            'operator' => $query->where('roles.can_use_operator_app', true),
            'admin', 'web' => $query->where('roles.can_use_admin_app', true),
            default => null,
        };

        return $query->exists();
    }

    public function hasClientPermission(string $permission, string $clientType): bool
    {
        $query = $this->roles()
            ->whereHas('permissions', fn ($query) => $query->where('permissions.name', $permission));

        if ($clientType === 'operator') {
            $query->where('roles.can_use_operator_app', true);
        } else {
            $query->where('roles.can_use_admin_app', true);
        }

        return $query->exists();
    }

    public function hasRole(string $role): bool
    {
        return $this->roles()->where('roles.name', $role)->exists();
    }

    public function canUseOperatorApp(): bool
    {
        $this->loadMissing('roles');

        return $this->roles->contains(fn (Role $role): bool => (bool) $role->can_use_operator_app);
    }

    public function canUseAdminApp(): bool
    {
        $this->loadMissing('roles');

        return $this->roles->contains(fn (Role $role): bool => (bool) $role->can_use_admin_app);
    }

    public function wantsBackupReport(string $result): bool
    {
        $preferences = is_array($this->mail_preferences) ? $this->mail_preferences : [];
        $backupReport = $preferences['backup_report'] ?? [];

        return is_array($backupReport) && (bool) ($backupReport[$result] ?? false);
    }

    public function belongsToTeamCode(string $code): bool
    {
        return $this->teams()->where('teams.code', $code)->exists();
    }

    private function currentClientType(): string
    {
        $token = $this->currentAccessToken();
        $abilities = is_array($token?->abilities ?? null) ? $token->abilities : [];

        if (in_array('client:admin', $abilities, true)) {
            return 'admin';
        }
        if (in_array('client:operator', $abilities, true)) {
            return 'operator';
        }
        if (in_array('client:web', $abilities, true)) {
            return 'web';
        }

        $tokenName = is_string($token?->name ?? null) ? strtolower($token->name) : '';
        if (str_contains($tokenName, 'admin android')) {
            return 'admin';
        }
        if (str_contains($tokenName, 'android')) {
            return 'operator';
        }

        return 'web';
    }
}
