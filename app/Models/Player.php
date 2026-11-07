<?php

namespace App\Models;

use App\Enums\Grade;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 選手(003)。表示名は暗号化して保存する(ADR-0003)。
 *
 * @property int $id
 * @property string $team_id
 * @property string $display_name
 * @property Grade $grade
 * @property int|null $number
 */
#[Fillable(['team_id', 'display_name', 'grade', 'number'])]
#[Hidden(['display_name'])]
class Player extends Model
{
    /** 1チームの選手の上限(AC-003-04) */
    public const int MAX_PER_TEAM = 200;

    /** 1人の選手に紐づけられる保護者の上限(AC-003-05) */
    public const int MAX_GUARDIANS = 4;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'display_name' => 'encrypted',
            'grade' => Grade::class,
            'number' => 'integer',
        ];
    }

    /**
     * @return HasMany<PlayerGuardian, $this>
     */
    public function guardians(): HasMany
    {
        return $this->hasMany(PlayerGuardian::class);
    }
}
