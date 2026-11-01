<?php

// Browser notifications (App\Notifications\Push). Short: the browser shows a line or two.
return [
    'review_submitted' => [
        'title' => 'Sent to review',
        'body' => '{1} :count work is in review on the marketplaces|[2,*] :count works are in review on the marketplaces',
    ],
];
