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
    'bot_contact' => '',   // e-mail для издателей, показывается на /bot.php (заполнить)
    'bot_ips' => [],       // публичные IP сервера краулера, показываются на /bot.php (заполнить)
    'max_attempts' => 5,      // неудачных входов с IP
    'attempt_window' => 900,  // за сколько секунд
];
