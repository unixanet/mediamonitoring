<?php
// Конфиг админки beta.mediamonitoring.am
$config = [
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
    'intranet_db' => [ // БД платформы v3 (интранет)
        'host' => 'localhost',
        'name' => 'intranet',
        'user' => 'intranet',
        'pass' => 'y>EQ7x,oz!r#g2;1',
    ],
    'xai' => [         // Grok API (xAI); модель выбирается по задаче, см. docs/PLATFORM_V3.md, раздел 3.4
        'api_key' => '',   // ключ лежит в config.local.php (в git не попадает, GitHub блокирует ключи xAI)
        'base_url' => 'https://api.x.ai/v1',
    ],
    'bot_contact' => '',   // e-mail для издателей, показывается на /bot.php (заполнить)
    'bot_ips' => [],       // публичные IP сервера краулера, показываются на /bot.php (заполнить)
    'max_attempts' => 5,      // неудачных входов с IP
    'attempt_window' => 900,  // за сколько секунд
];

if (is_file(__DIR__ . '/config.local.php')) {
    $config = array_replace_recursive($config, require __DIR__ . '/config.local.php');
}

return $config;
