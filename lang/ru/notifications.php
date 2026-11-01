<?php

// Browser notifications (App\Notifications\Push). Short: the browser shows a line or two.
return [
    'review_submitted' => [
        'title' => 'Отправлено на проверку',
        'body' => ':count работа на проверке на стоках|:count работы на проверке на стоках|:count работ на проверке на стоках',
    ],
];
