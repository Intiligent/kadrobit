<?php

return [
    'title' => 'Settings',

    'nav' => [
        'groups' => [
            'account' => 'Account',
            'workspace' => 'Workspace',
            'billing' => 'Billing',
        ],
        'profile' => 'Profile',
        'security' => 'Security',
        'notifications' => 'Notifications',
        'data' => 'Data & privacy',
        'marketplaces' => 'Marketplaces',
        'metadata' => 'Metadata defaults',
        'publishing' => 'Publishing',
        'billing' => 'Plan & billing',
        'soon' => 'Coming soon',
    ],

    'marketplaces' => [
        'title' => 'Marketplaces',
        'intro' => 'Connect the stock sites you sell on. Kadrobit signs in to each one over FTP or SFTP to deliver your files and metadata.',
        'add' => 'Add marketplace',
        'connected' => 'Connected',
        'available' => 'Available to connect',
        'connect' => 'Connect',
        'edit' => 'Edit',
        'remove' => 'Remove',
        'turn_on' => 'Turn on',
        'turn_off' => 'Turn off',
        'empty_title' => 'No marketplaces connected',
        'empty' => 'Add one to start publishing your works.',
        'unsupported' => ':names accept uploads only through their own websites, so they can\'t be connected.',
        'plain_ftp' => 'Unencrypted FTP',
        'plain_ftp_hint' => 'This marketplace accepts only plain FTP',

        'security' => [
            'summary' => 'Passwords are encrypted before they are saved and can\'t be viewed again, even by you. Only the upload service decrypts them, at the moment it sends files.',
            'more' => 'How it works',
            'less' => 'Hide',
            'points' => [
                'key' => ['title' => 'Separate encryption key', 'text' => 'Credentials are encrypted with their own key, kept outside the database. A copy of the database alone reveals nothing.'],
                'write_only' => ['title' => 'Write-only', 'text' => 'To change a password you enter a new one. The saved one is never sent back to the browser.'],
                'uploads_only' => ['title' => 'Used only for uploads', 'text' => 'A password is decrypted only to sign in to the marketplace. It never appears in logs.'],
                'removal' => ['title' => 'Deleted on removal', 'text' => 'Removing a marketplace deletes its login and password immediately.'],
            ],
        ],

        'status' => [
            'active' => 'Connected',
            'unreachable' => 'Server unreachable',
            'check_failed' => 'Check failed',
            'invalid_credentials' => 'Sign-in failed',
            'disabled' => 'Off',
            'checking' => 'Checking…',
            'checked' => 'Checked :time',
            'never_checked' => 'Not checked yet',
            'skipped' => 'Skipped when publishing',
            'signing_in' => 'Signing in to :host',
            'check_now' => 'Check now',
            'update_password' => 'Update password',
        ],

        'drawer' => [
            'pick_title' => 'Add marketplace',
            'pick_subtitle' => 'Choose the stock site to connect',
            'connect_title' => 'Connect :name',
            'edit_title' => 'Edit :name',
            'subtitle' => ':protocol upload',
            'connected_add_another' => 'Connected · add another',
            'back' => 'Back',
        ],

        'fields' => [
            'label' => 'Account name',
            'label_hint' => 'Shown only in Kadrobit. Helps when you have two accounts on one site.',
            'contributor_id' => 'Contributor ID',
            'contributor_id_placeholder' => 'Your author number on the marketplace',
            'optional' => 'Optional',
            'sign_in' => 'Upload sign-in',
            'host' => 'Server',
            'host_change' => 'Change',
            'login' => 'Login',
            'password' => 'Password',
            'ftp_password' => 'FTP password',
            'password_new' => 'New password',
            'password_saved' => 'Saved :date',
            'password_replace' => 'Replace',
            'password_hint' => 'Encrypted before saving. Kadrobit never shows it again.',
            'plain_ftp_title' => ':name accepts only plain FTP.',
            'plain_ftp' => 'The password travels to their server unencrypted. Use a password you don\'t use anywhere else.',
        ],

        'submit' => [
            'connect' => 'Check and connect',
            'save' => 'Check and save',
            'save_plain' => 'Save',
            'checking' => 'Checking…',
            'cancel' => 'Cancel',
        ],

        'validation' => [
            'host' => 'Enter the server name of the marketplace, e.g. ftp.example.com.',
            'host_unknown' => 'This server name does not exist. Check it in your marketplace contributor settings.',
        ],

        'check' => [
            'auth' => ':name rejected the login or password. Check them in your :name contributor settings.',
            'connect' => 'Could not reach :host. Check the server address, or try again later.',
            'other' => 'Could not sign in to :host. Try again later.',
        ],

        'toasts' => [
            'connected' => ':label connected',
            'saved' => ':label saved',
            'removed' => ':label removed. Its login and password are deleted',
            'turned_on' => ':label turned on',
            'turned_off' => ':label turned off. It\'s skipped when you publish',
            'still_failing' => ':label: sign-in still fails',
            'ok' => ':label: connection works',
        ],

        'remove_confirm' => [
            'title' => 'Remove :label?',
            'message' => 'The saved login and password are deleted right away. Works already sent to :name stay there. Uploads waiting in the queue for this account are cancelled.',
        ],

        // Shown at the top of the connect form, by marketplace code.
        'notes' => [
            'adobe_stock' => 'SFTP is available to qualified contributor accounts. The SFTP login and password are issued by Adobe, not your Adobe ID: find them under Upload → Learn More in the Adobe Stock Contributor portal.',
            'shutterstock' => 'Use your Shutterstock contributor login. Categories and the final submit for review are done on the Shutterstock website after the files arrive.',
            'pond5' => 'Pond5 uses a separate FTP password. Set it in your Pond5 account settings; your account password won\'t work here.',
            'dreamstime' => 'Use your Dreamstime login. Model and property releases are sent as a separate file.',
            'alamy' => 'Captions and tags are added in Alamy Image Manager after the upload.',
            'depositphotos' => 'Find the FTP server and login in your Depositphotos contributor settings.',
            '123rf' => 'Video goes to a separate 123RF server. The FTP folder holds up to 4 GB at a time.',
            'magnific' => 'Find the FTP details in your Freepik contributor panel. Titles and keywords are embedded into the files.',
            'vecteezy' => 'Find the FTP details in your Vecteezy contributor dashboard. Metadata is sent as a CSV next to the files.',
        ],
    ],
];
