<?php

namespace App\Models;

use App\Enums\FamilyRole;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use NotificationChannels\WebPush\HasPushSubscriptions;

/**
 * @property array<string, mixed>|null $dashboard_config
 * @property array<string, mixed>|null $email_preferences
 * @property array<string, mixed>|null $notification_preferences
 * @property Carbon|null $allergen_profile_reviewed_at
 */
class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasFactory, HasPushSubscriptions, HasUuids, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'google_id',
        'family_id',
        'family_role',
        'is_managed',
        'managed_by',
        'avatar',
        'avatar_color',
        'google_avatar',
        'date_of_birth',
        'timezone',
        'email_preferences',
        'notification_preferences',
        'easter_eggs_found',
        'onboarding_completed_at',
        // `allergen_profile_reviewed_at` is intentionally NOT fillable. The
        // UserAllergenController uses forceFill so a member can never silently
        // mark their profile reviewed (which would defeat the false-safe
        // allergen filter).
    ];

    /**
     * SECURITY: Attributes that should never come from user input.
     * These are only set explicitly in controllers/services.
     */
    protected $guarded_from_request = [
        'family_role',
        'google_id',
        'family_id',
        'is_managed',
        'managed_by',
        'dashboard_config',
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
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'family_role' => FamilyRole::class,
            'date_of_birth' => 'date',
            'is_managed' => 'boolean',
            'email_preferences' => 'json',
            'notification_preferences' => 'json',
            'easter_eggs_found' => 'array',
            'dashboard_config' => 'json',
            'onboarding_completed_at' => 'datetime',
            'allergen_profile_reviewed_at' => 'datetime',
        ];
    }

    public function allergens(): BelongsToMany
    {
        return $this->belongsToMany(Allergen::class, 'member_allergens')->using(MemberAllergen::class)->withTimestamps();
    }

    /**
     * User belongs to a Family.
     */
    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    /**
     * The parent user who manages this account (for managed child accounts).
     */
    public function managedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'managed_by');
    }

    /**
     * Child accounts managed by this parent.
     */
    public function managedChildren(): HasMany
    {
        return $this->hasMany(User::class, 'managed_by');
    }

    /**
     * Check if this is a managed (parent-controlled) account.
     */
    public function isManaged(): bool
    {
        return $this->is_managed;
    }

    /**
     * Get the user's current family as a query builder.
     * Used by controllers: $user->currentFamily()->firstOrFail()
     */
    public function currentFamily()
    {
        return Family::where('id', $this->family_id);
    }

    /**
     * User has many Tasks (created).
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'created_by');
    }

    /**
     * User has many assigned Tasks.
     */
    public function assignedTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'assigned_to');
    }

    /**
     * User has many Vault Entries (created).
     */
    public function vaultEntries(): HasMany
    {
        return $this->hasMany(VaultEntry::class, 'created_by');
    }

    /**
     * User has many Vault Permissions.
     */
    public function vaultPermissions(): HasMany
    {
        return $this->hasMany(VaultPermission::class);
    }

    /**
     * User has many Calendar Connections.
     */
    public function calendarConnections(): HasMany
    {
        return $this->hasMany(CalendarConnection::class);
    }

    /**
     * User has many Documents (uploaded).
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'uploaded_by');
    }

    /**
     * Check if user is a parent.
     */
    public function isParent(): bool
    {
        return $this->family_role === FamilyRole::Parent;
    }

    /**
     * Check if user is a child.
     */
    public function isChild(): bool
    {
        return $this->family_role === FamilyRole::Child;
    }

    /**
     * Check if user can access a vault entry.
     */
    public function canAccessVaultEntry(VaultEntry $entry): bool
    {
        // Creator can always access
        if ($entry->created_by === $this->id) {
            return true;
        }

        // Check if user has permission
        return $entry->permissions()
            ->where('user_id', $this->id)
            ->exists();
    }

    /**
     * Check if user can edit a vault entry.
     */
    public function canEditVaultEntry(VaultEntry $entry): bool
    {
        // Creator can always edit
        if ($entry->created_by === $this->id) {
            return true;
        }

        // Check if user has edit permission
        return $entry->permissions()
            ->where('user_id', $this->id)
            ->where('permission_level', 'edit')
            ->exists();
    }

    /**
     * User has many point transactions.
     */
    public function pointTransactions(): HasMany
    {
        return $this->hasMany(PointTransaction::class);
    }

    /**
     * User has many badges via pivot.
     */
    public function badges(): BelongsToMany
    {
        return $this->belongsToMany(Badge::class, 'user_badges')
            ->withPivot(['earned_at', 'awarded_by'])
            ->withTimestamps();
    }

    /**
     * Get the user's total point bank balance.
     */
    public function pointBank(): int
    {
        return (int) $this->pointTransactions()->sum('points');
    }

    /**
     * Get available points (bank minus active bid holds).
     */
    public function availablePoints(): int
    {
        $heldPoints = RewardBid::where('user_id', $this->id)
            ->whereNull('resolved_at')
            ->sum('held_points');

        return $this->pointBank() - (int) $heldPoints;
    }

    /**
     * Check if user has sufficient points for a purchase.
     */
    public function hasSufficientPoints(int $cost): bool
    {
        return $this->pointBank() >= $cost;
    }

    /**
     * Default shape for the unified notification_preferences JSON column.
     */
    public static function defaultNotificationPreferences(): array
    {
        return [
            'email' => [],
            'push' => [],
            'quiet_hours' => [
                'enabled' => false,
                'start' => '22:00',
                'end' => '07:00',
            ],
            'muted' => false,
            'dinner_reminder_at' => '15:00',
        ];
    }

    /**
     * Default email preferences derived from the notification type registry.
     * Kept as the source-of-truth for the legacy email_preferences shape.
     */
    public static function defaultEmailPreferences(): array
    {
        $defaults = [];
        foreach (config('notifications.types', []) as $key => $type) {
            if (in_array('email', $type['channels'] ?? [], true)) {
                // Legacy callers expect "email_<key>" shape; new callers use plain key.
                $defaults['email_'.$key] = (bool) ($type['default_email'] ?? true);
            }
        }

        return $defaults ?: [
            'email_task_completed' => true,
            'email_task_assigned' => true,
            'email_weekly_digest' => true,
            'email_family_invite' => true,
        ];
    }

    /**
     * Whether the user wants notifications of $key delivered via $channel.
     *
     * Reads notification_preferences first (new shape) and falls back to the
     * legacy email_preferences column. Falls back further to the registry's
     * default_<channel> when the user has no stored preference.
     */
    public function wants(string $channel, string $key): bool
    {
        // Email channel requires a deliverable address.
        if ($channel === 'email' && ! $this->email) {
            return false;
        }

        // Push channel requires at least one active subscription. We don't gate
        // on permission here — the SW silently drops unaddressed sends, but
        // the queries that drive scheduled notifications can short-circuit.
        if ($channel === 'push' && $this->getCachedPushSubscriptionCount() === 0) {
            return false;
        }

        $prefs = $this->notification_preferences ?? [];
        $channelPrefs = $prefs[$channel] ?? null;

        if (is_array($channelPrefs) && array_key_exists($key, $channelPrefs)) {
            return (bool) $channelPrefs[$key];
        }

        // Legacy fallback for the email channel.
        if ($channel === 'email') {
            $legacy = $this->email_preferences;
            if (is_array($legacy) && array_key_exists('email_'.$key, $legacy)) {
                return (bool) $legacy['email_'.$key];
            }
        }

        // Fall back to registry defaults.
        $type = config('notifications.types.'.$key);
        if (is_array($type)) {
            return (bool) ($type['default_'.$channel] ?? false);
        }

        return false;
    }

    /**
     * Push subscription count — honors eager loads from `with('pushSubscriptions')`
     * or `loadCount('pushSubscriptions')` so the scheduled-notification commands
     * don't trigger N+1 queries. Falls back to a single COUNT(*) when neither is
     * available. Not memoized: the caller may mutate subscriptions between calls
     * (see UserNotificationPreferencesTest::test_wants_push_requires_active_subscription).
     */
    private function getCachedPushSubscriptionCount(): int
    {
        if (array_key_exists('push_subscriptions_count', $this->attributes)) {
            return (int) $this->push_subscriptions_count;
        }

        if ($this->relationLoaded('pushSubscriptions')) {
            return $this->pushSubscriptions->count();
        }

        return $this->pushSubscriptions()->count();
    }

    /**
     * Whether push delivery is currently suppressed by global mute or quiet hours.
     * Email deliveries are unaffected — these are push-only suppression gates.
     */
    public function isPushSuppressed(): bool
    {
        return $this->isPushMuted() || $this->isInQuietHours();
    }

    public function isPushMuted(): bool
    {
        $prefs = $this->notification_preferences ?? [];

        return (bool) ($prefs['muted'] ?? false);
    }

    public function isInQuietHours(?Carbon $now = null): bool
    {
        $prefs = $this->notification_preferences ?? [];
        $quiet = $prefs['quiet_hours'] ?? null;

        if (! is_array($quiet) || empty($quiet['enabled'])) {
            return false;
        }

        $start = $quiet['start'] ?? null;
        $end = $quiet['end'] ?? null;
        if (! $start || ! $end) {
            return false;
        }

        $tz = $this->timezone ?: 'UTC';
        $now = ($now ?? Carbon::now())->copy()->setTimezone($tz);
        $minute = $now->hour * 60 + $now->minute;

        $startMinute = self::minutesFromTime($start);
        $endMinute = self::minutesFromTime($end);

        if ($startMinute === null || $endMinute === null) {
            return false;
        }

        // Same-day window (e.g., 13:00 → 17:00).
        if ($startMinute < $endMinute) {
            return $minute >= $startMinute && $minute < $endMinute;
        }

        // Overnight window (e.g., 22:00 → 07:00).
        return $minute >= $startMinute || $minute < $endMinute;
    }

    private static function minutesFromTime(string $value): ?int
    {
        if (! preg_match('/^([0-9]{1,2}):([0-9]{2})$/', $value, $m)) {
            return null;
        }
        $h = (int) $m[1];
        $mn = (int) $m[2];
        if ($h < 0 || $h > 23 || $mn < 0 || $mn > 59) {
            return null;
        }

        return $h * 60 + $mn;
    }

    /**
     * @deprecated Prefer wants('email', $key). Retained as a thin wrapper for
     * existing notification classes; calls through to the new implementation
     * with the legacy "email_" prefix stripped if present.
     */
    public function wantsEmail(string $key): bool
    {
        $stripped = str_starts_with($key, 'email_') ? substr($key, 6) : $key;

        return $this->wants('email', $stripped);
    }
}
