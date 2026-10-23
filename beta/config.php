<?php
// Конфиг админки beta.mediamonitoring.am
return [
    'db' => [          // БД админки
        'host' => 'localhost',
        'name' => 'nebuchadnezzar',
        'user' => 'nebuchadnezzar',
        'pass' => 'x,Tn}k<g<?OtViYY',
    ],
    'crawler_db' => [  // БД краулера (источники, статьи)
        'host' => 'localhost',
        'name' => 'crawler',
        'user' => 'crawler',
        'pass' => 'u]~6.!KW23gG1`4-',
    ],
    'max_attempts' => 5,      // неудачных входов с IP
    'attempt_window' => 900,  // за сколько секунд
];
