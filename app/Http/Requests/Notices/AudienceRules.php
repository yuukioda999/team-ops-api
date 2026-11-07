<?php

namespace App\Http\Requests\Notices;

use App\Enums\Grade;
use App\Enums\NoticeTarget;
use App\Enums\TeamRole;
use App\UseCases\Notices\NoticeAudience;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * 宛先の指定の検査(005)。全員なら値は空、学年・役割なら1つ以上。学年と役割は同時に指定できない。
 */
final class AudienceRules
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(string $required): array
    {
        return [
            'target' => [$required, 'array'],
            'target.type' => ['required_with:target', Rule::enum(NoticeTarget::class)],
            'target.values' => ['present_with:target', 'array', 'max:10'],
            'target.values.*' => ['string', 'distinct'],
        ];
    }

    public static function after(Validator $validator): void
    {
        /** @var array<string, mixed> $data */
        $data = $validator->getData();
        $target = $data['target'] ?? null;
        if (! is_array($target) || $validator->errors()->has('target') || $validator->errors()->has('target.type') || $validator->errors()->has('target.values')) {
            return;
        }
        $type = NoticeTarget::tryFrom((string) ($target['type'] ?? ''));
        $values = is_array($target['values'] ?? null) ? $target['values'] : [];
        $allowed = match ($type) {
            NoticeTarget::Grades => array_map(fn (Grade $g) => $g->value, Grade::cases()),
            NoticeTarget::Roles => array_map(fn (TeamRole $r) => $r->value, TeamRole::cases()),
            default => [],
        };

        if ($type === NoticeTarget::All && $values !== []) {
            $validator->errors()->add('target.values', '全員に送るときは、学年や役割を選べません。');
        } elseif ($type !== NoticeTarget::All && $type !== null && $values === []) {
            $validator->errors()->add('target.values', $type === NoticeTarget::Grades ? '学年を1つ以上選んでください。' : '役割を1つ以上選んでください。');
        } elseif (array_diff($values, $allowed) !== []) {
            $validator->errors()->add('target.values', '選べない値が含まれています。');
        }
    }

    /**
     * @param  array<string, mixed>  $target
     */
    public static function audience(array $target): NoticeAudience
    {
        $values = is_array($target['values'] ?? null) ? array_values(array_map('strval', $target['values'])) : [];

        return new NoticeAudience(NoticeTarget::from((string) $target['type']), $values);
    }
}
