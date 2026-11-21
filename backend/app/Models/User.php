<?php

namespace App\Models;

use App\Enums\SexualRole;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * A registered account.
 *
 * @property int $id
 * @property string $nickname
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $avatar_path
 * @property string|null $avatar_mime
 * @property int|null $avatar_size
 * @property UserStatus $status
 * @property SexualRole|null $sexual_role
 * @property Carbon|null $last_seen_at
 * @property UserRole $role
 * @property bool $is_banned
 * @property bool $is_muted
 * @property Carbon|null $muted_until
 * @property Carbon|null $last_login_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ConversationUser|null $pivot
 */
#[Fillable([
    'nickname',
    'email',
    'password',
    'avatar_path',
    'avatar_mime',
    'avatar_size',
    'status',
    'sexual_role',
    'last_seen_at',
    'role',
    'is_banned',
    'is_muted',
    'muted_until',
    'last_login_at',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'last_login_at' => 'datetime',
            'muted_until' => 'datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
            'sexual_role' => SexualRole::class,
            'role' => UserRole::class,
            'is_banned' => 'boolean',
            'is_muted' => 'boolean',
            'avatar_size' => 'integer',
        ];
    }

    /**
     * Conversations this user takes part in.
     *
     * @return BelongsToMany<Conversation, $this, ConversationUser>
     */
    public function conversations(): BelongsToMany
    {
        return $this->belongsToMany(Conversation::class)
            ->using(ConversationUser::class)
            ->withPivot(['last_read_at', 'unread_count'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * @return HasMany<UserDevice, $this>
     */
    public function devices(): HasMany
    {
        return $this->hasMany(UserDevice::class);
    }

    /**
     * @return HasMany<MediaAsset, $this>
     */
    public function mediaAssets(): HasMany
    {
        return $this->hasMany(MediaAsset::class);
    }

    /**
     * @return HasMany<Report, $this>
     */
    public function reportsAgainst(): HasMany
    {
        return $this->hasMany(Report::class, 'reported_user_id');
    }

    /**
     * @return HasMany<Ban, $this>
     */
    public function bans(): HasMany
    {
        return $this->hasMany(Ban::class);
    }

    /**
     * @return HasMany<Mute, $this>
     */
    public function mutes(): HasMany
    {
        return $this->hasMany(Mute::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function blockedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'blocks', 'blocker_id', 'blocked_id')
            ->withPivot('reason')
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function blockedBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'blocks', 'blocked_id', 'blocker_id')
            ->withTimestamps();
    }

    /**
     * Restrict a query to accounts that are allowed to authenticate.
     *
     * @param  Builder<User>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_banned', false);
    }

    /**
     * Whether the account has a usable nickname.
     */
    public function hasNickname(): bool
    {
        return $this->nickname !== '';
    }
}
