<?php

// The members pages and mails. A site changes single keys in its own lang/en/members.php.
// :name parameters are filled by t(); {name} placeholders in the mails by the mailer.
return [
    'form' => [
        'name' => 'Name',
        'email' => 'Email',
        'password' => 'Password',
        'password_hint' => 'At least :min characters.',
        'consent' => 'I agree that my data is used for this account (:link).',
        'privacy' => 'privacy policy',
        'submit' => 'Create account',
        'registered' => 'Already registered?',
        'login' => 'Log in',
    ],
    'closed' => [
        'title' => 'Registration is closed',
        'text' => 'New registrations are paused at the moment. If you already have an account, you can log in.',
    ],
    'errors' => [
        'login' => 'Email or password is wrong.',
        'summary' => 'Please check the highlighted fields.',
        'name' => 'Please enter your name.',
        'email' => 'Please enter a valid email address.',
        'password' => 'Use at least :min characters.',
        'consent' => 'Please agree so your account can be created.',
        'throttled' => 'Too many attempts, try again later.',
    ],
    'inbox' => [
        'title' => 'Check your inbox',
        'text' => 'A link is on its way to :email. Click it to confirm your address.',
        'hint' => 'The link works for 24 hours. Nothing there? Look in your spam folder.',
    ],
    'confirm' => [
        'title' => 'Confirm your email',
        'text' => "One click and you're done.",
        'button' => 'Confirm my email',
        'used_title' => 'This link no longer works',
        'used' => 'This link was already used or has expired. You can log in, or register again.',
        'login' => 'Log in',
        'register' => 'Register again',
    ],
    'thanks' => [
        'tag' => 'Waiting for approval',
        'title' => 'Thanks for your interest',
        'text' => "Your email is confirmed. Your registration will be reviewed, and you'll get an email once it is decided.",
    ],
    'login' => [
        'title' => 'Log in',
        'submit' => 'Log in',
        'forgot' => 'Forgot your password?',
        'no_account' => 'No account yet?',
        'register' => 'Register',
    ],
    'forgot' => [
        'title' => 'Forgot your password?',
        'text' => "Enter your email and you'll get a link to choose a new one.",
        'submit' => 'Send the link',
        'sent' => 'If an account exists for this address, a link is on its way.',
        'back' => 'Back to login',
    ],
    'reset' => [
        'title' => 'Choose a new password',
        'password' => 'New password',
        'repeat' => 'Repeat the password',
        'submit' => 'Save password',
        'mismatch' => "The two passwords don't match.",
        'expired_title' => 'This link has expired',
        'expired' => 'This link was already used or has expired. Ask for a new one.',
        'again' => 'Send a new link',
    ],
    'resend' => [
        'title' => 'Please confirm your email first',
        'text' => "Your password is right, but your address isn't confirmed yet. Have the link sent again.",
        'button' => 'Send the link again',
    ],
    'account' => [
        'title' => 'Your account',
        'changed' => 'Password changed.',
        'deleted' => 'Your account is deleted.',
        'current' => 'Current password',
        'password_title' => 'Change your password',
        'password_submit' => 'Save new password',
        'delete_title' => 'Delete your account',
        'delete_text' => 'This removes your account and your personal data. It cannot be undone. You can register again at any time.',
        'confirm' => 'I understand this deletes my account.',
        'delete_submit' => 'Delete my account',
        'errors' => [
            'current' => 'The current password is wrong.',
            'delete_current' => 'The current password is wrong.',
            'mismatch' => "The two passwords don't match.",
            'confirm' => 'Please confirm that you understand this deletes your account.',
        ],
    ],
    // Subject and body of each mail. accepted/declined: the body is the Decision panel's default
    // message (used when Members settings leave it empty); the owner edits it before sending.
    // notice goes to the owner in the site's default locale; {details} are the admission's lines.
    'mail' => [
        'confirm' => [
            'subject' => 'Confirm your email address',
            'body' => "Hi {name},\n\nplease confirm your email address: {link}\n\nThe link works for 24 hours. If you didn't sign up, ignore this mail.",
        ],
        'existing' => [
            'subject' => 'Your account',
            'body' => "Hi {name},\n\nsomeone (hopefully you) tried to register with this address, but you already have an account.\n\nLog in: {login_url}\nForgot your password? {reset_url}",
        ],
        'reset' => [
            'subject' => 'Reset your password',
            'body' => "Hi {name},\n\nset a new password here: {link}\n\nThe link works for one hour. If you didn't ask for this, ignore this mail.",
        ],
        'notice' => [
            'subject' => '{name} is waiting for approval',
            'body' => "{name} <{email}> confirmed their email and is waiting for approval.{details}\n\nOpen in Folio: {folio_url}",
        ],
        'accepted' => [
            'subject' => 'Your account is ready',
            'body' => "Hi {name},\n\nyour account is ready. Sign in here: {login_url}\n\nBest regards",
        ],
        'declined' => [
            'subject' => 'About your registration',
            'body' => "Hi {name},\n\nthank you for your interest. Unfortunately we can't give you an account at this time.\n\nBest regards",
        ],
    ],
];
