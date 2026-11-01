<?php

return [
    'failed' => 'These credentials do not match our records.',
    'password' => 'The provided password is incorrect.',
    'throttle' => 'Too many login attempts. Please try again in :seconds seconds.',

    'validation' => [
        'username' => 'The username may contain only letters, numbers, dots, underscores and dashes, 3 to 20 characters.',
    ],

    'fields' => [
        'first_name' => 'First name',
        'last_name' => 'Last name',
        'username' => 'Username',
        'email' => 'Email',
        'password' => 'Password',
    ],

    'login' => [
        'title' => 'Log in',
        'subtitle' => 'Welcome back. Log in to continue.',
        'remember' => 'Remember me',
        'submit' => 'Log in',
        'no_account' => 'No account yet?',
        'register_link' => 'Create one',
    ],

    'register' => [
        'title' => 'Create account',
        'subtitle' => 'Sign up to start managing your stock content.',
        'username_hint' => 'Letters, numbers, dots, underscores and dashes.',
        'password_hint' => 'At least 8 characters.',
        'submit' => 'Create account',
        'has_account' => 'Already have an account?',
        'login_link' => 'Log in',
    ],

    'forgot' => [
        'link' => 'Forgot password?',
        'title' => 'Forgot your password?',
        'subtitle' => 'Enter your email and we will send you a link to choose a new password.',
        'submit' => 'Send reset link',
        'back_to_login' => 'Back to log in',
    ],

    'reset' => [
        'title' => 'Set a new password',
        'subtitle' => 'Choose a new password for your account.',
        'password_confirmation' => 'Confirm password',
        'submit' => 'Reset password',
        'request_new' => 'Request a new link',
    ],

    'google' => [
        'continue' => 'Continue with Google',
        'or' => 'or',
        'cancelled' => 'Google sign-in was cancelled.',
        'failed' => 'Could not sign in with Google. Please try again.',
        'unverified_email' => 'Google has not confirmed this email address. Log in with your password instead.',
    ],

    'authorize' => [
        'title' => 'Allow access',
        'subtitle' => ':client asks to connect to your Kadrobit account :email.',
        'scopes' => [
            'extension' => 'See your connected marketplaces and the works sent to them, and record their IDs and review status',
        ],
        'approve' => 'Allow',
        'deny' => 'Cancel',
    ],

    'logout' => 'Log out',
];
