<?php
// Скопировать в data/config.php и подставить свой хеш.
// Хеш получить так:
//   php -r "echo password_hash('пароль', PASSWORD_DEFAULT);"
return [
    'password_hash' => '$2y$12$ЗАМЕНИТЬ',
    'session_name'  => 'proadm',
];
