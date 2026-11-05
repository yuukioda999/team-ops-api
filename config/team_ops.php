<?php

return [

    /*
    | メールアドレスの照合用ハッシュ(ブラインドインデックス)の鍵。APP_KEY とは別にする(ADR-0003)。
    | base64: で始まる場合はデコードして使う。
    */
    'blind_index_key' => env('BLIND_INDEX_KEY'),

    /*
    | デモモード。宛先を .invalid ドメインだけに制限する(notify-relay 側でも同じ制限をかける)。
    */
    'demo_mode' => (bool) env('DEMO_MODE', false),

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
