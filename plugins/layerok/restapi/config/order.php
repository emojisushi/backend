<?php

return [
    // 492 - production
    // 184 - stage
    'sushi_sticks_poster_id' => env('SUSHI_STICKS_POSTER_ID', 492),
    'training_sticks_poster_id' => env('TRAINING_STICKS_POSTER_ID', 491),

    // Poster reads delivery_time in the account's own timezone. The server runs
    // UTC, so a naive timestamp arrives hours in the past and is rejected.
    'timezone' => env('ORDER_TIMEZONE', 'Europe/Kyiv'),
];
