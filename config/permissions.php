<?php

use App\Enums\Role;

return [

    /*
    |--------------------------------------------------------------------------
    | Role Permission Matrix
    |--------------------------------------------------------------------------
    | Every role maps to a list of permissions it has.
    | The 'admin' role is a super role and implicitly has EVERY permission.
    |
    | Roles: customer, organizer, event_staff, admin (see App\Enums\Role).
    |
    | Usage in middleware:  ->middleware('permission:create_bookings')
    | Usage in controllers: $user->hasPermission('create_bookings')
    */

    'roles' => [

        Role::ADMIN->value => [
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
            'manage_tickets',
            'manage_event_staff',
            'view_dashboard',
            'view_reports',
            'view_payments',
        ],

        Role::ORGANIZER->value => [
            'manage_own_events',
            'manage_own_ticket_types',
            'manage_tickets',
            'manage_own_event_images',
            'view_own_event_bookings',
            'manage_own_checkins',
            'scan_tickets',
            'view_attendance',
            'manage_event_staff',
            'manage_organizer_profile',
            'view_venues',
            'view_categories',
            'view_organizers',
        ],

        Role::EVENT_STAFF->value => [
            'scan_tickets',
            'view_own_attendance',
            'view_venues',
            'view_categories',
            'view_events',
        ],

        Role::CUSTOMER->value => [
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
            'view_own_tickets',
        ],

    ],

];
