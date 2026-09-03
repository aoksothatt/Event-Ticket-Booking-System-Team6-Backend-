<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Role Permission Matrix
    |--------------------------------------------------------------------------
    | Every role maps to a list of permissions it has.
    | The 'admin' role is a super role and implicitly has EVERY permission.
    |
    | Usage in middleware:  ->middleware('permission:create_bookings')
    | Usage in controllers: $user->hasPermission('create_bookings')
    */

    'roles' => [

        'admin' => [
            'manage_users',
            'manage_organizers',
            'manage_venues',
            'manage_categories',
            'manage_events',
            'manage_ticket_types',
            'manage_bookings',
            'manage_payments',
            'manage_reviews',
            'manage_checkins',
            'view_dashboard',
        ],

        'organizer' => [
            'manage_own_events',
            'manage_own_ticket_types',
            'manage_own_event_images',
            'view_own_event_bookings',
            'manage_own_checkins',
            'manage_organizer_profile',
            'view_venues',
            'view_categories',
            'view_organizers',
        ],

        'customer' => [
            'view_events',
            'view_venues',
            'view_categories',
            'view_organizers',
            'create_bookings',
            'view_own_bookings',
            'cancel_own_bookings',
            'make_payments',
            'create_reviews',
            'manage_own_profile',
        ],

    ],

];
