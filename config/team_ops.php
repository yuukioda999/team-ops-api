<?php

return [

    /*
    | メールアドレスの照合用ハッシュ(ブラインドインデックス)の鍵。APP_KEY とは別にする(ADR-0003)。
    | base64: で始まる場合はデコードして使う。
    */
    'blind_index_key' => env('BLIND_INDEX_KEY'),

    /*
    | デモモード(spec 007)。公開デモ用の振る舞いを有効にする。デモモードでは宛先の制限も必ず有効になる。
    */
    'demo_mode' => (bool) env('DEMO_MODE', false),

    /*
    | 宛先を .invalid ドメインだけに制限する。ローカルの開発環境は、デモモードにせずにこれだけを有効にする
    | (マジックリンクでログインできるまま、実在のアドレスには送らない)。
    | 入力の検査(EmailAddressRequest)、アウトボックス、notify-relay の3か所で同じ制限をかける。
    */
    'invalid_recipients_only' => (bool) env('RECIPIENTS_INVALID_ONLY', false) || (bool) env('DEMO_MODE', false),

    /*
    | 信頼するプロキシ(Vite・nginx)のアドレスか範囲。カンマ区切り。回数制限で利用者ごとのIPを
    | 正しく数えるために使う。ここにないアドレスから届いた X-Forwarded-For は使わない。
    | 本番では nginx のアドレスだけを書き、nginx は X-Forwarded-For を $remote_addr で上書きする。
    */
    'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))))),

    /*
    | Web 画面の URL。メールに書くリンク(ログイン、メール変更の確認)の先頭に使う。
    */
    'web_url' => rtrim((string) env('WEB_URL', 'http://localhost:5173'), '/'),

    /*
    | ログイン用・メール変更用のリンクの有効期間(分)と、1時間に要求できる回数(AC-001-03・05)。
    */
    'login_link' => [
        'ttl_minutes' => 15,
        'per_hour_per_email' => 5,
        'per_hour_per_ip' => 30,
    ],

    /*
    | notify-relay(メール配信)への接続(ADR-0002)。依頼には HMAC 署名を付ける。
    | secret は base64 で、notify-relay の RELAY_SIGNING_KEYS と同じ値にする。
    */
    'notify_relay' => [
        'url' => env('NOTIFY_RELAY_URL', 'http://notify-relay:8080'),
        'key_id' => env('NOTIFY_RELAY_KEY_ID'),
        'secret' => env('NOTIFY_RELAY_SECRET'),
        'timeout' => (int) env('NOTIFY_RELAY_TIMEOUT', 10),
    ],

];
