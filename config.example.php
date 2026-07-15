<?php

return [
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => 'wdlgdgmy_id_card',
        'username' => 'root',
        'password' => '',
        'charset' => 'utf8mb4',
    ],
    'app' => [
        'name' => 'JoSTUM ID Report Centre',
        'base_url' => '',
        'university_name' => 'Joseph Sarwuan Tarka University, Makurdi',
        'report_tagline' => 'Styled ID card records reporting for staff and students',
    ],
    'pdf' => [
        'max_records' => 5000,
    ],
    'mail' => [
        'mailer' => 'smtp',
        'host' => 'smtp.gmail.com',
        'port' => 587,
        'username' => 'jostumidcard@gmail.com',
        'password' => 'casmbwivqujxhoiu',
        'encryption' => 'tls',
        'from_name' => 'AFRO VILLAGE MARKET',
        'from_email' => 'jostumidcard@gmail.com',
        'batch_size' => 4,
        'batch_interval_seconds' => 120,
        'max_retries' => 3,
        'retry_delay_seconds' => 300,
        'send_delay_microseconds' => 350000,
        'php_binary' => 'C:\\xampp\\php\\php.exe',
    ],
];
