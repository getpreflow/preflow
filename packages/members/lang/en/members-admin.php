<?php

// Admin texts of preflow/members (Folio; scope admin, unlike the public "members" group).
// A site changes single keys in its own lang/en/members-admin.php.
// types/sections/fields: the labels of models/member.json and of the settings form (Preflow\Data\ModelLabelKeys).
// :state is a member's state (states.*), :name the member's name, :language a language (decision.languages.*), :date a date.
return [
    'types' => [
        'member' => ['label' => 'Members', 'singular' => 'Member', 'empty' => 'No members yet.'],
        'members_settings' => ['label' => 'Members settings', 'singular' => 'Members settings'],
    ],
    'sections' => [
        'members' => 'Members',
    ],
    'fields' => [
        'member' => [
            'email' => ['label' => 'Email'],
            'name' => ['label' => 'Name'],
            'language' => ['label' => 'Language'],
            'registered_at' => ['label' => 'Registered At'],
            'email_confirmed_at' => ['label' => 'Email Confirmed At'],
            'decided_at' => ['label' => 'Decided At'],
            'locked_at' => ['label' => 'Locked At'],
            'last_login_at' => ['label' => 'Last Sign-in'],
            'roles' => ['label' => 'Roles'],
        ],
        'members_settings' => [
            'registration_open' => ['label' => 'Registration open'],
            'notice_email' => ['label' => 'Notify about waiting members', 'help' => 'Address that gets a mail when someone waits for approval'],
            'accept_message' => ['label' => 'Accept message', 'help' => 'Pre-filled in the Decision panel; empty means the standard text shown in the field. {name} and {login_url} are filled in.'],
            'decline_message' => ['label' => 'Decline message', 'help' => 'Pre-filled in the Decision panel; empty means the standard text shown in the field. {name} is filled in.'],
        ],
    ],
    // The member list's column and the states it shows.
    'list' => ['state' => 'State'],
    'states' => [
        'unconfirmed' => 'Unconfirmed',
        'waiting' => 'Waiting',
        'member' => 'Member',
        'declined' => 'Declined',
    ],
    // The Decision panel on a member, and its flashes.
    'decision' => [
        'title' => 'Decision',
        'registered' => 'Registered :date',
        'unconfirmed' => 'Not confirmed yet — nothing to decide.',
        'accept' => 'Accept',
        'decline' => 'Decline',
        'accept_send' => 'Accept and send',
        'decline_send' => 'Decline and send',
        'message_to' => 'Message to :name',
        'sent_in' => 'Sent in :language. The default comes from Members settings; edit it freely.',
        'empty_message' => 'The message is empty.',
        'cant_accept' => ':state: can’t accept — nothing was changed.',
        'cant_decline' => ':state: can’t decline — nothing was changed.',
        'mail_failed' => 'The mail could not be sent — nothing was changed.',
        'languages' => [
            'en' => 'English',
            'de' => 'German',
            'fr' => 'French',
            'it' => 'Italian',
            'es' => 'Spanish',
            'nl' => 'Dutch',
            'pt' => 'Portuguese',
            'rm' => 'Romansh',
        ],
    ],
    'errors' => [
        'email_taken' => 'Another member already uses this email address.',
    ],
    // The roles screen: the extension's permissions and their heading.
    'permissions' => [
        'access' => 'Use the members area',
        'decide' => 'Accept or decline members',
    ],
    'extension' => ['label' => 'Members'],
    // The roles the extension seeds (population => name), as long as their label is the seeded one.
    'roles' => [
        'members' => [
            'pending' => 'Waiting for approval',
            'member' => 'Member',
            'rejected' => 'Rejected',
        ],
    ],
];
