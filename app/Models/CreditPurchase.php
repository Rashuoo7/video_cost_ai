<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Credits a user bought (a fake purchase: no payment is taken).
 *
 * @property int $id
 * @property int|null $user_id
 * @property int $amount nanodollars
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable(['amount'])]
class CreditPurchase extends Model
{
    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
