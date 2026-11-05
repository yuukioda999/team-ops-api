<?php

/*
 * 入力エラーの文言。画面にそのまま出すため、利用者が直し方を分かる書き方にする。
 * 使っているルールだけを置き、増えたら足す。
 */
return [
    'array' => ':attributeの形式が正しくありません。',
    'between' => [
        'string' => ':attributeは:min〜:max文字で入力してください。',
    ],
    'email' => ':attributeの形式が正しくありません。',
    'enum' => ':attributeの選択肢から選んでください。',
    'in' => ':attributeの選択肢から選んでください。',
    'max' => [
        'string' => ':attributeは:max文字以内で入力してください。',
    ],
    'not_regex' => ':attributeに使えない文字(改行など)が含まれています。',
    'min' => [
        'string' => ':attributeは:min文字以上で入力してください。',
    ],
    'required' => ':attributeを入力してください。',
    'string' => ':attributeは文字で入力してください。',
    'ulid' => ':attributeの形式が正しくありません。',

    'attributes' => [
        'email' => 'メールアドレス',
        'new_email' => '新しいメールアドレス',
        'display_name' => '表示名',
        'token' => 'リンク',
    ],
];
