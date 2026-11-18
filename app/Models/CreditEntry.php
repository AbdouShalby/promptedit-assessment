<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * One line of a user's credit ledger. Written only through App\Billing\CreditLedger.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $generation_id
 * @property string $type
 * @property int $amount_nano
 * @property string|null $idempotency_key
 * @property CarbonInterface $created_at
 */
class CreditEntry extends Model
{
    public const PURCHASE = 'purchase';

    public const RESERVE = 'reserve';   // hold the maximum estimate when a generation is submitted

    public const RELEASE = 'release';   // give the hold back when the generation is final

    public const CHARGE = 'charge';     // take the actual cost of a succeeded generation

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
