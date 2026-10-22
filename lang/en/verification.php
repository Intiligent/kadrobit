<?php

return [
    'title' => 'Confirm your email',
    'subtitle' => 'One last step to finish setting up your account.',
    'sent_to' => 'We sent a :length-digit code to :email.',
    'code_label' => 'Confirmation code',
    'submit' => 'Confirm',
    'spam_hint' => 'The email arrives within a minute. If it is not there, check the Spam folder.',
    'resend' => 'Send a new code',
    'resend_in' => 'Send a new code in :seconds s',
    'change_email' => 'Wrong address? Change it',
    'change_email_save' => 'Save and send a code',
    'change_email_cancel' => 'Cancel',

    'banner' => [
        'text' => 'Confirm your email address to finish setting up your account.',
        'action' => 'Confirm now',
    ],

    'flash' => [
        'verified' => 'Your email address has been confirmed.',
        'resent' => 'A new code has been sent.',
        'email_updated' => 'The address has been changed, a new code is on its way.',
    ],

    'errors' => [
        'invalid' => 'The code is incorrect. Check it and try again.',
        'expired' => 'The code has expired. Request a new one.',
        'missing' => 'The code is no longer valid. Request a new one.',
        'too_many_attempts' => 'Too many wrong attempts: the code has been cancelled. Request a new one.',
        'cooldown' => 'Please wait :seconds seconds before requesting a new code.',
        'link_expired' => 'This link is no longer valid. Enter the code from the latest email or request a new one.',
        'daily_limit' => 'You have requested too many codes today. Please try again tomorrow.',
        'email_unavailable' => 'This email address cannot be used. Please try another one.',
    ],

    'email' => [
        'subject' => ':code is your :app confirmation code',
        'preheader' => ':code is your confirmation code.',
        'title' => 'Confirm your email',
        'greeting' => 'Hi :name,',
        'intro' => 'Enter this code on the confirmation screen to confirm your email address:',
        'expire' => 'The code is valid for :count minutes.',
        'action_hint' => 'Or confirm with one click:',
        'action' => 'Confirm email',
        'outro' => 'If you did not create an account, ignore this email: nothing will happen.',
        'link_hint' => 'If the button does not work, copy this link into your browser:',
    ],
];
