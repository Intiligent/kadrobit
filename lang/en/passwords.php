<?php

return [
    'reset' => 'Your password has been reset. Log in with the new password.',
    // Same answer whether or not the email has an account, so the form does not reveal registered emails.
    'sent' => 'If an account with this email exists, we have sent a password reset link to it.',
    'throttled' => 'Please wait before retrying.',
    'token' => 'This password reset link is invalid or has expired. Request a new one.',
    'user' => 'This password reset link is invalid or has expired. Request a new one.',

    'email' => [
        'subject' => 'Reset your :app password',
        'title' => 'Reset your password',
        'greeting' => 'Hi :name,',
        'intro' => 'We received a request to reset the password for your account. Click the button below to choose a new one.',
        'action' => 'Reset password',
        'expire' => '{1} The link expires in :count minute.|[2,*] The link expires in :count minutes.',
        'outro' => 'If you did not request a password reset, ignore this email: your password stays the same.',
        'link_hint' => 'If the button does not work, copy this link into your browser:',
    ],
];
