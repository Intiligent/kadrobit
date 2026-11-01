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
        'general' => 'General',
        'security' => 'Security',
        'notifications' => 'Notifications',
        'data' => 'Data & privacy',
        'marketplaces' => 'Marketplaces',
        'metadata' => 'Metadata defaults',
        'publishing' => 'Publishing',
        'billing' => 'Plan & billing',
        'soon' => 'Coming soon',
    ],

    'validation' => [
        'unknown_setting' => 'This setting does not exist.',
    ],

    'general' => [
        'title' => 'General',
        'intro' => 'Language and other preferences of your account.',
        'locale' => [
            'title' => 'Language',
            'text' => 'The language of the interface and of the emails we send you. Titles and keywords of your works are generated in English, as marketplaces require.',
            // Each language is named in itself, the same in every language file.
            'options' => [
                'en' => 'English',
                'ru' => 'Русский',
            ],
        ],
    ],

    'metadata' => [
        'title' => 'Metadata defaults',
        'intro' => 'How Kadrobit fills in the title, description, keywords and categories of your works.',
        'autofill' => [
            'title' => 'Fill in metadata on upload',
            'text' => 'When a work finishes processing, empty fields are filled in from the image. Fields you have edited are never replaced, and you can regenerate or restore the generated text at any time.',
        ],
        'toasts' => [
            'autofill_on' => 'Metadata will be filled in on upload',
            'autofill_off' => 'Metadata won\'t be filled in on upload',
        ],
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
        'portal' => 'Portal',
        'portal_hint' => 'Open your :name contributor account',

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
            'contributor_id_login' => 'Used as the upload login',
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
            'saved_off' => ':label saved but kept off: sign-in failed',
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
            'adobe_stock' => 'SFTP is available to qualified contributor accounts. Your contributor ID is the SFTP login. The SFTP password is issued by Adobe and differs from your Adobe ID password: find it under Upload → Learn More in the Adobe Stock Contributor portal.',
            'shutterstock' => 'Use your Shutterstock contributor login. Categories and the final submit for review are done on the Shutterstock website after the files arrive.',
            'pond5' => 'Pond5 uses a separate FTP password. Set it in your Pond5 account settings; your account password won\'t work here.',
            'dreamstime' => 'The contributor ID is the User ID from the FTP upload page of your Dreamstime account (a number, not the email); the password is the account one. Model and property releases are sent as a separate file.',
            'alamy' => 'The login is your Alamy account email, the password is the account\'s. Alamy\'s server takes plain FTP only, without encryption. Alamy takes the caption and tags from the file; the description and the rest are filled in Image Manager.',
            'depositphotos' => 'The login and password are your Depositphotos account\'s. Depositphotos\' server takes plain FTP only, without encryption. Metadata and the submission for review are completed on the Depositphotos website once the files arrive.',
            '123rf' => 'The login is your 123RF username (not the email), the password is the account\'s; if you sign in to 123RF with Google, set a password on the Upload Content page first. After sending, open Upload Content → Upload Via FTP on 123RF and click Proceed: only then do the works become drafts. 123RF reads the description and keywords from the file. The FTP folder holds up to 4 GB at a time.',
            'magnific' => 'Find the FTP details in your Freepik contributor panel. Titles and keywords are embedded into the files.',
            'vecteezy' => 'The SFTP login and password are on the Upload page of your Vecteezy account; Vecteezy gives them only after onboarding (the first 25 files through the website, ID verification, review): your website email and password do not work. The title, description, keywords and license (Pro, Editorial for editorial works) go as a CSV with each work. To have the works go to review right away, turn on Auto Submit in Upload Settings and turn off Magic Metadata: it replaces your metadata with Vecteezy\'s own. Vecteezy takes the category and releases on the website only.',
        ],
    ],
];
