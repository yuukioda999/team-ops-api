<?php

namespace App\Http\Requests\Auth;

use App\Support\Crypto\BlindIndex;
use App\Support\NotifyRelay\RecipientRestriction;
use Illuminate\Foundation\Http\FormRequest;

/**
 * メールアドレスを1つ受け取る入力(ログイン用リンクの要求、メール変更の申請)。
 *
 * 受け付けるのは半角(ASCII)のアドレスだけにし、前後の空白を除いて小文字にそろえる。
 * 照合用のハッシュ値と実際の宛先が、必ず同じ値から作られるようにするため
 * (全角で書いた別のアドレスが、他人のアカウントと同じものとして扱われるのを防ぐ)。
 */
abstract class EmailAddressRequest extends FormRequest
{
    abstract protected function field(): string;

    protected function prepareForValidation(): void
    {
        $value = $this->input($this->field());
        if (is_string($value)) {
            $this->merge([$this->field() => trim($value)]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // filter(PHP の FILTER_VALIDATE_EMAIL)は ASCII 以外を通さない
        $rules = ['required', 'string', 'max:254', 'email:rfc,filter'];

        // 宛先を .invalid に限る設定では、アウトボックスが .invalid 以外を受け付けない。ここで先に断り、500 ではなく項目のエラーにする
        if (RecipientRestriction::invalidOnly()) {
            $rules[] = function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && ! str_ends_with(BlindIndex::normalizeEmail($value), '.invalid')) {
                    $fail('この環境では、.invalid で終わるメールアドレスだけ使えます。');
                }
            };
        }

        return [$this->field() => $rules];
    }

    /**
     * 正規化したメールアドレス。照合にも保存にも送信にもこの値を使う。
     */
    public function address(): string
    {
        return BlindIndex::normalizeEmail((string) $this->validated($this->field()));
    }
}
