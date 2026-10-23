<?php

return [
    'title' => 'Library',

    'nav' => [
        'library' => 'Library',
        'publishing' => 'Publishing',
        'marketplaces' => 'Marketplaces',
        'settings' => 'Settings',
        'soon' => ':section: coming soon',
    ],

    'explorer' => [
        'library' => 'Library',
        'new_folder_soon' => 'New folder: coming soon',
        'views' => [
            'all' => 'All works',
            'unsorted' => 'Unsorted',
        ],
        'folders' => 'Folders',
        'marketplaces' => 'Marketplaces',
        'manage' => 'Manage',
        'no_accounts' => 'No marketplaces connected yet',
        'account_status' => [
            'active' => 'Connected',
            'invalid_credentials' => 'Reconnect',
            'disabled' => 'Off',
        ],
    ],

    'toolbar' => [
        'works' => '{0} No works|{1} :count work|[2,*] :count works',
        'search' => 'Search title, keywords, description',
        'size_large' => 'Large tiles',
        'size_small' => 'Small tiles',
        'types' => [
            'all' => 'All types',
            'photo' => 'Photos',
            'video' => 'Videos',
            'vector' => 'Vectors',
            'illustration' => 'Illustrations',
        ],
        'sort' => [
            'newest' => 'Newest first',
            'oldest' => 'Oldest first',
            'keywords' => 'Fewest keywords',
            'title' => 'Title A–Z',
        ],
    ],

    'status' => [
        'all' => 'All',
        'draft' => 'Draft',
        'ready' => 'Ready',
        'archived' => 'Archived',
    ],

    'types' => [
        'photo' => 'Photo',
        'video' => 'Video',
        'vector' => 'Vector',
        'illustration' => 'Illustration',
    ],

    'grid' => [
        'empty_library_title' => 'No works yet',
        'empty_library' => 'Upload photos and videos with the first tile.',
        'empty_filtered' => 'Nothing in this view.',
        'empty_search' => 'Nothing matches “:query”.',
    ],

    'upload' => [
        'tile' => 'Upload',
        'tile_hint' => 'or drop photos and videos',
        'drop_title' => 'Drop to upload',
        'drop_hint' => 'Photos, videos and vectors, any number of files',
        'too_large' => 'The file is larger than :max MB.',
        'unsupported' => 'This file type is not supported.',
        'duplicate' => ':name is already in your library.',
        'failed' => 'Upload failed',
        'processing_failed' => 'The file could not be processed.',
        'cancelled' => 'The upload was cancelled.',
        'status' => [
            'title_active' => 'Uploading {1} :count file|[2,*] :count files',
            'title_done' => 'Uploads complete',
            'progress' => ':done of :total',
            'queued' => 'Waiting',
            'uploading' => 'Uploading',
            'in_queue' => 'In the processing queue',
            'processing' => 'Processing',
            'done' => 'Uploaded',
            'retry' => 'Retry',
            'remove' => 'Remove',
            'clear' => 'Clear list',
            'open' => 'Uploads',
        ],
    ],

    'panel' => [
        'close' => 'Close',
        'tabs' => [
            'metadata' => 'Metadata',
            'marketplaces' => 'Marketplaces',
            'file' => 'File info',
        ],
    ],

    'card' => [
        'untitled' => 'Untitled · :file',
        'keywords' => '{1} :count keyword|[2,*] :count keywords',
        'no_keywords' => 'No keywords yet',
    ],
];
