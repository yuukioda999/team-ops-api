<?php

namespace App\Models\Concerns;

use App\Support\Crypto\BlindIndex;
use Illuminate\Database\Eloquent\Builder;

/**
 * 暗号化した email 列から、照合用の email_index を作る(ADR-0003)。
 *
 * @property string $email
 * @property string $email_index
 */
trait HasEmailBlindIndex
{
    protected static function bootHasEmailBlindIndex(): void
    {
        static::saving(function (self $model): void {
            if ($model->isDirty('email') || ! $model->exists) {
                $model->email_index = app(BlindIndex::class)->email($model->email);
            }
        });
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeWhereEmail(Builder $query, string $email): void
    {
        $query->where('email_index', app(BlindIndex::class)->email($email));
    }
}
