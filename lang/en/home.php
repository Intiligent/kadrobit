<?php

return [
    'title' => 'Publish to stock sites',
    'description' => 'Kadrobit writes titles and keywords, checks files against each stock site’s rules and delivers them over FTP and SFTP.',

    'nav' => [
        'how' => 'How it works',
        'features' => 'Features',
        'checks' => 'Checks',
        'marketplaces' => 'Marketplaces',
        'language' => 'Language',
    ],
    'login' => 'Log in',
    'signup' => 'Create account',

    'hero' => [
        'line1' => 'Upload once.',
        'line2' => 'Sell',
        'accent' => 'everywhere.',
        'text' => 'Kadrobit writes titles and keywords, checks files against each stock site’s rules and delivers them over FTP and SFTP. It works for your first uploads and for portfolios of thousands of files.',
        'secondary' => 'How it works',
    ],

    // Statuses in the animated demos
    'demo' => [
        'uploading' => 'Uploading :pct%',
        'uploaded' => 'Uploaded',
        'sending' => 'Sending :pct%',
        'status' => [
            'wait' => 'Waiting',
            'ok' => 'Will be sent',
            'warning' => 'Needs attention',
            'error' => 'Won’t be sent',
            'sent' => 'Sent',
            'queued' => 'Queued',
            'outdated' => 'Outdated',
        ],
    ],

    'stocks' => [
        'lead' => 'Sends to',
        'unsupported' => ':names take uploads only through their own websites, so they can’t be connected.',
    ],

    'how' => [
        'title_before' => 'From memory card to review in ',
        'title_accent' => 'four',
        'title_after' => ' frames',
        'submit_button' => 'Submit for review',
        'steps' => [
            'upload' => ['title' => 'Upload files', 'text' => 'Drop photos, videos or vectors into your library. Each file is uploaded once, for every stock site.'],
            'review' => ['title' => 'Review metadata', 'text' => 'AI suggests a title, description and keywords. Adjust what you need and mark the work as ready.'],
            'send' => ['title' => 'Send a batch', 'text' => 'Pick the ready works and the stock sites. Files go out from Kadrobit’s server, so you can close the tab.'],
            'submit' => ['title' => 'Submit for review', 'text' => 'Files appear in each stock site’s upload area. You submit them for review on the marketplace website.'],
        ],
    ],

    'features' => [
        'title_before' => 'The busywork you no longer do ',
        'title_accent' => 'by hand',
        'items' => [
            'ai' => ['title' => 'AI metadata', 'text' => 'A title, description and up to 50 English keywords. The first ten are highlighted, since stock sites weigh them most.'],
            'rules' => ['title' => 'Each site’s rules', 'text' => 'No need to remember where 4 MP is the minimum or a category is required. Kadrobit tells you before sending.'],
            'batches' => ['title' => 'Batches of hundreds', 'text' => 'Send everything that’s ready at once, and untick single works or sites.'],
            'status' => ['title' => 'Status on every site', 'text' => 'Queued, sending, sent or failed. Edit a work after sending and it’s marked as outdated.'],
            'library' => ['title' => 'Library with folders', 'text' => 'Search, sorting, status tabs and drag-and-drop between folders.'],
            'server' => ['title' => 'Sent from our server', 'text' => 'You upload a file once; Kadrobit sends it to the stock sites. A heavy 4K clip doesn’t go through your connection five times.'],
        ],
        'rules' => [
            'adobe_stock' => '4 MP minimum',
            'shutterstock' => 'category on site',
            'pond5' => 'price on site',
        ],
        'route' => [
            'you' => 'You',
            'stocks' => ':count stock sites',
        ],
    ],

    'checks' => [
        'title_before' => 'Catch problems ',
        'title_accent' => 'before',
        'title_after' => ' review',
        'text' => 'Before sending you see what each stock site will get. Files a marketplace is certain to reject are not sent. Issues you can fix on the stock site don’t stop the upload.',
        'reasons' => [
            'ok' => 'All checks passed',
            'warning' => 'Pick a category on the Shutterstock website',
            'error' => '3.1 MP resolution, 4 MP minimum',
        ],
    ],

    'footer' => [
        'terms' => 'Terms',
        'privacy' => 'Privacy',
        'support' => 'Support',
    ],
];
