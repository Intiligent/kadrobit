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
        'extension' => 'Browser extension',
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

    'notifications' => [
        'title' => 'Notifications',
        'intro' => 'What Kadrobit tells you about, and where.',
        'browser' => [
            'title' => 'Browser notifications',
            'text' => 'Arrive even when Kadrobit is closed, while the browser is running. Turned on for each browser separately.',
            'on' => 'On in this browser',
            'turn_on' => 'Turn on in this browser',
            'turn_off' => 'Turn off',
            'unsupported' => 'This browser does not support notifications from websites.',
            'unavailable' => 'Browser notifications are not set up on this server yet.',
            'blocked' => 'Notifications from Kadrobit are blocked in this browser. Allow them in the site settings (the icon to the left of the address), then reload the page.',
        ],
        'events' => [
            'title' => 'Events',
            'review_submitted' => [
                'title' => 'Works sent to review',
                'text' => 'When the works you sent have all reached review on the marketplaces. The Kadrobit extension submits them.',
            ],
            'example' => 'Show an example',
            'example_hint' => 'Turn on notifications in this browser to see an example',
        ],
        'toasts' => [
            'browser_on' => 'Notifications are on in this browser',
            'browser_off' => 'Notifications are off in this browser',
            'browser_failed' => 'Could not turn on notifications in this browser',
            'event_on' => 'You will be notified: :event',
            'event_off' => 'You won\'t be notified: :event',
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

    'extension' => [
        'title' => 'Browser extension',
        'intro' => 'The Kadrobit extension for Chrome finishes publishing on stock sites without an API: it finds your files there, fills in what the files don\'t carry and sends the works to review, in your own session.',
        'download' => [
            'title' => 'Kadrobit for Chrome',
            'version' => 'Version :version, :date',
            'missing' => 'The extension hasn\'t been built yet.',
            'button' => 'Download',
        ],
        'install' => [
            'title' => 'How to install',
            'steps' => [
                'download' => 'Download the archive and keep it where it won\'t be deleted.',
                'unzip' => 'Unpack it into a folder.',
                'open' => 'In Chrome, open chrome://extensions.',
                'developer' => 'Turn on Developer mode in the top right corner.',
                'load' => 'Click "Load unpacked" and choose the unpacked folder.',
                'sign_in' => 'Click the Kadrobit icon in the toolbar and sign in: Kadrobit asks you to allow the extension.',
            ],
            'update' => 'To update, unpack the new version into the same folder and click the reload button of Kadrobit on chrome://extensions. You stay signed in.',
            'id' => 'Extension ID: :id',
        ],
        'connections' => [
            'title' => 'Connected extensions',
            'empty' => 'No extension is connected yet.',
            'kind' => [
                'oauth' => 'Signed in from the extension',
                'personal' => 'Personal token',
            ],
            'dates' => 'Since :issued · until :expires unless used',
            'disconnect' => 'Disconnect',
            'cancel' => 'Cancel',
            'disconnect_confirm' => [
                'title' => 'Disconnect the extension?',
                'message' => 'It stops working at once. To use it again, sign in from the extension.',
            ],
            'disconnected' => 'Extension disconnected',
        ],
        'token' => [
            'title' => 'Personal token',
            'text' => 'If the sign-in window doesn\'t open in your browser, create a token and paste it into the extension instead. It works for a year and can be disconnected above.',
            'issue' => 'Create token',
            'copy' => 'Copy',
            'copied' => 'Token copied',
            'once' => 'It is shown only now: copy it before leaving the page.',
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
        'auto_submit' => 'Auto submit from the extension',
        'auto_submit_needs' => 'Auto submit to review on :name works in Chrome with the Kadrobit extension installed and signed in, and the auto submit box ticked here. Install and sign in on Settings → Browser extension.',
        'auto_submit_short' => 'Auto submit',
        'auto_submit_hint' => 'The Kadrobit extension sends works to review on the :name website by itself, accepting the site\'s terms where that takes it. Turned off, it only finds the files and you submit them yourself.',
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
            'auto_submit_on' => 'The extension will submit :label works to review',
            'auto_submit_off' => 'The extension won\'t submit :label works: do it on the website',
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
            '123rf' => 'The login is your 123RF username (not the email), the password is the account\'s; if you sign in to 123RF with Google, set a password on the Upload Content page first. The works reach 123RF on its FTP; with auto submit on, the Kadrobit extension clicks Proceed in Upload Via FTP and sends the drafts to review while a 123RF tab is open. Without the extension, open Upload Content → Upload Via FTP, click Proceed and submit the drafts yourself. 123RF reads the description and keywords from the file. The FTP folder holds up to 4 GB at a time.',
            'magnific' => 'Find the FTP details in your Freepik contributor panel. Titles and keywords are embedded into the files.',
            'vecteezy' => 'The SFTP login and password are on the Upload page of your Vecteezy account; Vecteezy gives them only after onboarding (the first 25 files through the website, ID verification, review): your website email and password do not work. The title, description, keywords and license (Pro, Editorial for editorial works) go as a CSV with each work. To have the works go to review right away, turn on Auto Submit in Upload Settings and turn off Magic Metadata: it replaces your metadata with Vecteezy\'s own. Vecteezy takes the category and releases on the website only.',
        ],
    ],
];
