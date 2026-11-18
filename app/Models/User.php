<?php

namespace App\Models;

use App\Enums\GenerationStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * Email verification is off (no MustVerifyEmail): the app sends no real mail, and anyone should be able to sign up.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property bool $is_admin can see the spend report
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * @return HasMany<Generation, $this>
     */
    public function generations(): HasMany
    {
        return $this->hasMany(Generation::class);
    }

    /**
     * @return HasMany<CreditPurchase, $this>
     */
    public function creditPurchases(): HasMany
    {
        return $this->hasMany(CreditPurchase::class);
    }

    /**
     * Credits in nanodollars, derived from the records rather than kept as a running total that could drift: what was
     * bought, minus what generations were charged. Each unfinished generation holds the most it could cost, so the
     * credits can't be overspent while final prices are unknown; the update that charges a generation also ends its hold.
     *
     * @return array{balance: int, held: int, available: int}
     */
    public function creditBalance(): array
    {
        $unfinished = GenerationStatus::unfinished();

        // One statement, one snapshot: a generation that finishes mid-read (its status and cost change together) is
        // counted exactly once, as held or as charged. Purchases can be read separately, as they only ever add credits.
        $generations = $this->generations()->toBase()->selectRaw(
            'COALESCE(SUM(cost), 0) AS charged, COALESCE(SUM(CASE WHEN status IN ('.implode(', ', array_fill(0, count($unfinished), '?')).') THEN max_cost END), 0) AS held',
            $unfinished,
        )->sole();

        $balance = (int) $this->creditPurchases()->sum('amount') - (int) $generations->charged;
        $held = (int) $generations->held;

        return ['balance' => $balance, 'held' => $held, 'available' => $balance - $held];
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
            'two_factor_confirmed_at' => 'datetime',
            'is_admin' => 'boolean',
        ];
    }
}
