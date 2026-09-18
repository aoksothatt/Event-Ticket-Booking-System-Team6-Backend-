<?php

/*
|--------------------------------------------------------------------------
| Settings Catalog
|--------------------------------------------------------------------------
|
| Central definition of every platform setting. The `settings` table only
| stores values an administrator has explicitly saved; anything without a
| stored row resolves to the default defined here, so existing behaviour is
| preserved until a setting is changed.
|
| Each entry:
|   'group'   - UI group this key belongs to.
|   'type'    - scalar type used for casting ('string'|'boolean'|'integer'|'color').
|   'label'   - i18n key for the field label (frontend locales).
|   'desc'    - i18n key for the field hint (frontend locales).
|   'default' - fallback value when no row exists. May be a closure so it can
|               read other configuration lazily at resolution time.
|   'rules'   - Laravel validation rules applied on update.
|   'options' - allowed option values for select/radio fields (optional).
|
*/

return [

    'general.platform_name' => [
        'group' => 'general',
        'type' => 'string',
        'label' => 'settings.general.platformName',
        'desc' => 'settings.general.platformNameDesc',
        'default' => 'EventHub',
        'rules' => ['required', 'string', 'max:100'],
    ],

    'general.platform_description' => [
        'group' => 'general',
        'type' => 'string',
        'label' => 'settings.general.platformDescription',
        'desc' => 'settings.general.platformDescriptionDesc',
        'default' => 'Event & Ticket Booking Platform',
        'rules' => ['nullable', 'string', 'max:500'],
    ],

    'general.support_email' => [
        'group' => 'general',
        'type' => 'string',
        'label' => 'settings.general.supportEmail',
        'desc' => 'settings.general.supportEmailDesc',
        'default' => 'support@eventhub.com',
        'rules' => ['nullable', 'string', 'email', 'max:255'],
    ],

    'general.support_phone' => [
        'group' => 'general',
        'type' => 'string',
        'label' => 'settings.general.supportPhone',
        'desc' => 'settings.general.supportPhoneDesc',
        'default' => '',
        'rules' => ['nullable', 'string', 'max:30'],
    ],

    'general.default_currency' => [
        'group' => 'general',
        'type' => 'string',
        'label' => 'settings.general.defaultCurrency',
        'desc' => 'settings.general.defaultCurrencyDesc',
        'default' => 'USD',
        'options' => ['USD', 'EUR', 'KHR', 'GBP'],
        'rules' => ['required', 'string', 'in:USD,EUR,KHR,GBP'],
    ],

    'general.timezone' => [
        'group' => 'general',
        'type' => 'string',
        'label' => 'settings.general.timezone',
        'desc' => 'settings.general.timezoneDesc',
        'default' => 'Asia/Phnom_Penh',
        'options' => [
            'UTC', 'Asia/Phnom_Penh', 'Asia/Bangkok', 'Asia/Singapore',
            'Asia/Hong_Kong', 'Asia/Tokyo', 'Europe/London', 'Europe/Paris',
            'America/New_York', 'America/Los_Angeles', 'Australia/Sydney',
        ],
        'rules' => ['required', 'string', 'max:100'],
    ],

    'general.language' => [
        'group' => 'general',
        'type' => 'string',
        'label' => 'settings.general.language',
        'desc' => 'settings.general.languageDesc',
        'default' => 'en',
        'options' => ['en', 'km'],
        'rules' => ['required', 'string', 'in:en,km'],
    ],

    'appearance.logo' => [
        'group' => 'appearance',
        'type' => 'string',
        'label' => 'settings.appearance.logo',
        'desc' => 'settings.appearance.logoDesc',
        'default' => '',
        'rules' => ['nullable', 'string', 'max:255'],
    ],

    'appearance.favicon' => [
        'group' => 'appearance',
        'type' => 'string',
        'label' => 'settings.appearance.favicon',
        'desc' => 'settings.appearance.faviconDesc',
        'default' => '',
        'rules' => ['nullable', 'string', 'max:255'],
    ],

    'appearance.primary_color' => [
        'group' => 'appearance',
        'type' => 'color',
        'label' => 'settings.appearance.primaryColor',
        'desc' => 'settings.appearance.primaryColorDesc',
        'default' => '#f59e0b',
        'rules' => ['required', 'string', 'regex:/^#([0-9a-fA-F]{6})$/'],
    ],

    'appearance.secondary_color' => [
        'group' => 'appearance',
        'type' => 'color',
        'label' => 'settings.appearance.secondaryColor',
        'desc' => 'settings.appearance.secondaryColorDesc',
        'default' => '#0f172a',
        'rules' => ['required', 'string', 'regex:/^#([0-9a-fA-F]{6})$/'],
    ],

    'appearance.theme' => [
        'group' => 'appearance',
        'type' => 'string',
        'label' => 'settings.appearance.theme',
        'desc' => 'settings.appearance.themeDesc',
        'default' => 'system',
        'options' => ['light', 'dark', 'system'],
        'rules' => ['required', 'string', 'in:light,dark,system'],
    ],

    'appearance.tagline' => [
        'group' => 'appearance',
        'type' => 'string',
        'label' => 'settings.appearance.tagline',
        'desc' => 'settings.appearance.taglineDesc',
        'default' => 'Discover. Book. Experience.',
        'rules' => ['nullable', 'string', 'max:200'],
    ],

    'appearance.footer_copyright' => [
        'group' => 'appearance',
        'type' => 'string',
        'label' => 'settings.appearance.footerCopyright',
        'desc' => 'settings.appearance.footerCopyrightDesc',
        'default' => '© 2026 EventHub. All rights reserved.',
        'rules' => ['nullable', 'string', 'max:200'],
    ],

    'booking.enabled' => [
        'group' => 'booking',
        'type' => 'boolean',
        'label' => 'settings.booking.enabled',
        'desc' => 'settings.booking.enabledDesc',
        'default' => true,
        'rules' => ['required', 'boolean'],
    ],

    'booking.min_tickets' => [
        'group' => 'booking',
        'type' => 'integer',
        'label' => 'settings.booking.minTickets',
        'desc' => 'settings.booking.minTicketsDesc',
        'default' => 1,
        'rules' => ['required', 'integer', 'min:1'],
    ],

    'booking.max_tickets' => [
        'group' => 'booking',
        'type' => 'integer',
        'label' => 'settings.booking.maxTickets',
        'desc' => 'settings.booking.maxTicketsDesc',
        'default' => 10,
        'rules' => ['required', 'integer', 'min:1', 'gte:settings.booking.min_tickets'],
    ],

    'booking.cancellation_enabled' => [
        'group' => 'booking',
        'type' => 'boolean',
        'label' => 'settings.booking.cancellationEnabled',
        'desc' => 'settings.booking.cancellationEnabledDesc',
        'default' => true,
        'rules' => ['required', 'boolean'],
    ],

    'booking.cancellation_deadline' => [
        'group' => 'booking',
        'type' => 'integer',
        'label' => 'settings.booking.cancellationDeadline',
        'desc' => 'settings.booking.cancellationDeadlineDesc',
        'default' => 24,
        'rules' => ['required', 'integer', 'min:0'],
    ],

    'booking.auto_expire' => [
        'group' => 'booking',
        'type' => 'boolean',
        'label' => 'settings.booking.autoExpire',
        'desc' => 'settings.booking.autoExpireDesc',
        'default' => true,
        'rules' => ['required', 'boolean'],
    ],

    'booking.expiration_minutes' => [
        'group' => 'booking',
        'type' => 'integer',
        'label' => 'settings.booking.expirationMinutes',
        'desc' => 'settings.booking.expirationMinutesDesc',
        'default' => 15,
        'rules' => ['required', 'integer', 'min:1'],
    ],

    'booking.require_phone' => [
        'group' => 'booking',
        'type' => 'boolean',
        'label' => 'settings.booking.requirePhone',
        'desc' => 'settings.booking.requirePhoneDesc',
        'default' => false,
        'rules' => ['required', 'boolean'],
    ],

    'booking.require_email_verification' => [
        'group' => 'booking',
        'type' => 'boolean',
        'label' => 'settings.booking.requireEmailVerification',
        'desc' => 'settings.booking.requireEmailVerificationDesc',
        // Default is OFF deliberately: this codebase has no email-verification
        // flow, and all pre-existing accounts are unverified. A `true` default
        // (or existing stored value) would permanently block checkout with no
        // way to unblock. Administrators may still enable the requirement.
        'default' => false,
        'rules' => ['required', 'boolean'],
    ],

    'payment.enabled' => [
        'group' => 'payment',
        'type' => 'boolean',
        'label' => 'settings.payment.enabled',
        'desc' => 'settings.payment.enabledDesc',
        'default' => true,
        'rules' => ['required', 'boolean'],
    ],

    'payment.currency' => [
        'group' => 'payment',
        'type' => 'string',
        'label' => 'settings.payment.currency',
        'desc' => 'settings.payment.currencyDesc',
        'default' => 'USD',
        'options' => ['USD', 'KHR'],
        'rules' => ['required', 'string', 'in:USD,KHR'],
    ],

    'payment.bakong_enabled' => [
        'group' => 'payment',
        'type' => 'boolean',
        'label' => 'settings.payment.bakongEnabled',
        'desc' => 'settings.payment.bakongEnabledDesc',
        'default' => true,
        'rules' => ['required', 'boolean'],
    ],

    'payment.timeout' => [
        'group' => 'payment',
        'type' => 'integer',
        'label' => 'settings.payment.timeout',
        'desc' => 'settings.payment.timeoutDesc',
        'default' => fn () => (int) config('bakong.qr_expiration_minutes', 15),
        'rules' => ['required', 'integer', 'min:1'],
    ],

    'payment.automatic_verification' => [
        'group' => 'payment',
        'type' => 'boolean',
        'label' => 'settings.payment.automaticVerification',
        'desc' => 'settings.payment.automaticVerificationDesc',
        'default' => true,
        'rules' => ['required', 'boolean'],
    ],

    'notification.email_enabled' => [
        'group' => 'notification',
        'type' => 'boolean',
        'label' => 'settings.notification.emailEnabled',
        'desc' => 'settings.notification.emailEnabledDesc',
        'default' => true,
        'rules' => ['required', 'boolean'],
    ],

    'notification.customer_booking_confirmation' => [
        'group' => 'notification',
        'type' => 'boolean',
        'label' => 'settings.notification.customerBookingConfirmation',
        'default' => true,
        'rules' => ['required', 'boolean'],
    ],

    'notification.customer_payment_successful' => [
        'group' => 'notification',
        'type' => 'boolean',
        'label' => 'settings.notification.customerPaymentSuccessful',
        'default' => true,
        'rules' => ['required', 'boolean'],
    ],

    'notification.customer_payment_failed' => [
        'group' => 'notification',
        'type' => 'boolean',
        'label' => 'settings.notification.customerPaymentFailed',
        'default' => true,
        'rules' => ['required', 'boolean'],
    ],

    'notification.customer_ticket_issued' => [
        'group' => 'notification',
        'type' => 'boolean',
        'label' => 'settings.notification.customerTicketIssued',
        'default' => true,
        'rules' => ['required', 'boolean'],
    ],

    'notification.customer_booking_cancelled' => [
        'group' => 'notification',
        'type' => 'boolean',
        'label' => 'settings.notification.customerBookingCancelled',
        'default' => true,
        'rules' => ['required', 'boolean'],
    ],

    'notification.customer_event_reminder' => [
        'group' => 'notification',
        'type' => 'boolean',
        'label' => 'settings.notification.customerEventReminder',
        'default' => true,
        'rules' => ['required', 'boolean'],
    ],

    'notification.organizer_new_booking' => [
        'group' => 'notification',
        'type' => 'boolean',
        'label' => 'settings.notification.organizerNewBooking',
        'default' => true,
        'rules' => ['required', 'boolean'],
    ],

    'notification.organizer_payment_received' => [
        'group' => 'notification',
        'type' => 'boolean',
        'label' => 'settings.notification.organizerPaymentReceived',
        'default' => true,
        'rules' => ['required', 'boolean'],
    ],

    'notification.organizer_event_approved' => [
        'group' => 'notification',
        'type' => 'boolean',
        'label' => 'settings.notification.organizerEventApproved',
        'default' => true,
        'rules' => ['required', 'boolean'],
    ],

    'notification.organizer_event_cancelled' => [
        'group' => 'notification',
        'type' => 'boolean',
        'label' => 'settings.notification.organizerEventCancelled',
        'default' => true,
        'rules' => ['required', 'boolean'],
    ],

    'user.registration_enabled' => [
        'group' => 'user',
        'type' => 'boolean',
        'label' => 'settings.user.registrationEnabled',
        'desc' => 'settings.user.registrationEnabledDesc',
        'default' => true,
        'rules' => ['required', 'boolean'],
    ],

    'user.email_verification' => [
        'group' => 'user',
        'type' => 'boolean',
        'label' => 'settings.user.emailVerification',
        'desc' => 'settings.user.emailVerificationDesc',
        'default' => true,
        'rules' => ['required', 'boolean'],
    ],

    'user.google_login' => [
        'group' => 'user',
        'type' => 'boolean',
        'label' => 'settings.user.googleLogin',
        'desc' => 'settings.user.googleLoginDesc',
        'default' => true,
        'rules' => ['required', 'boolean'],
    ],

    'user.profile_editing' => [
        'group' => 'user',
        'type' => 'boolean',
        'label' => 'settings.user.profileEditing',
        'desc' => 'settings.user.profileEditingDesc',
        'default' => true,
        'rules' => ['required', 'boolean'],
    ],

    'user.account_deletion' => [
        'group' => 'user',
        'type' => 'boolean',
        'label' => 'settings.user.accountDeletion',
        'desc' => 'settings.user.accountDeletionDesc',
        'default' => false,
        'rules' => ['required', 'boolean'],
    ],

    'user.default_role' => [
        'group' => 'user',
        'type' => 'string',
        'label' => 'settings.user.defaultRole',
        'desc' => 'settings.user.defaultRoleDesc',
        'default' => 'customer',
        'options' => ['customer', 'organizer'],
        'rules' => ['required', 'string', 'in:customer,organizer'],
    ],

    'event.organizer_create_enabled' => [
        'group' => 'event',
        'type' => 'boolean',
        'label' => 'settings.event.organizerCreateEnabled',
        'desc' => 'settings.event.organizerCreateEnabledDesc',
        'default' => true,
        'rules' => ['required', 'boolean'],
    ],

    'event.admin_approval_required' => [
        'group' => 'event',
        'type' => 'boolean',
        'label' => 'settings.event.adminApprovalRequired',
        'desc' => 'settings.event.adminApprovalRequiredDesc',
        'default' => false,
        'rules' => ['required', 'boolean'],
    ],

    'event.allow_edit_after_publish' => [
        'group' => 'event',
        'type' => 'boolean',
        'label' => 'settings.event.allowEditAfterPublish',
        'desc' => 'settings.event.allowEditAfterPublishDesc',
        'default' => true,
        'rules' => ['required', 'boolean'],
    ],

    'event.allow_cancellation' => [
        'group' => 'event',
        'type' => 'boolean',
        'label' => 'settings.event.allowCancellation',
        'desc' => 'settings.event.allowCancellationDesc',
        'default' => true,
        'rules' => ['required', 'boolean'],
    ],

    'event.auto_handle_past' => [
        'group' => 'event',
        'type' => 'boolean',
        'label' => 'settings.event.autoHandlePast',
        'desc' => 'settings.event.autoHandlePastDesc',
        'default' => true,
        'rules' => ['required', 'boolean'],
    ],

    'ticket.qr_enabled' => [
        'group' => 'event',
        'type' => 'boolean',
        'label' => 'settings.ticket.qrEnabled',
        'desc' => 'settings.ticket.qrEnabledDesc',
        'default' => true,
        'rules' => ['required', 'boolean'],
    ],

    'ticket.allow_download' => [
        'group' => 'event',
        'type' => 'boolean',
        'label' => 'settings.ticket.allowDownload',
        'desc' => 'settings.ticket.allowDownloadDesc',
        'default' => true,
        'rules' => ['required', 'boolean'],
    ],

    'ticket.allow_printing' => [
        'group' => 'event',
        'type' => 'boolean',
        'label' => 'settings.ticket.allowPrinting',
        'desc' => 'settings.ticket.allowPrintingDesc',
        'default' => true,
        'rules' => ['required', 'boolean'],
    ],

    'ticket.auto_mark_used' => [
        'group' => 'event',
        'type' => 'boolean',
        'label' => 'settings.ticket.autoMarkUsed',
        'desc' => 'settings.ticket.autoMarkUsedDesc',
        'default' => true,
        'rules' => ['required', 'boolean'],
    ],

    'system.maintenance_mode' => [
        'group' => 'system',
        'type' => 'boolean',
        'label' => 'settings.system.maintenanceMode',
        'desc' => 'settings.system.maintenanceModeDesc',
        'default' => false,
        'rules' => ['required', 'boolean'],
    ],

    'system.maintenance_message' => [
        'group' => 'system',
        'type' => 'string',
        'label' => 'settings.system.maintenanceMessage',
        'desc' => 'settings.system.maintenanceMessageDesc',
        'default' => "We're currently performing maintenance. Please come back later.",
        'rules' => ['nullable', 'string', 'max:500'],
    ],

];