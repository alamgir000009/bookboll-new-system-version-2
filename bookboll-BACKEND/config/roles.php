<?php

return [

    'guard' => 'web',

    'staff_roles' => [
        'super_admin',
        'admin',
        'senior_employee',
        'junior_employee',
        'guard',
    ],

    /** Provider / business-owner roles (Bookboll for Business). */
    'provider_roles' => [
        'provider_admin',
    ],

    'protected_roles' => [
        'super_admin',
        'customer',
        'provider_admin',
    ],

    'modules' => [
        'dashboard',
        'calendar',
        'bookings',
        'payments',
        'venues',
        'listings',
        'special-days',
        'resources',
        'add-ons',
        'promocodes',
        'loyalty',
        'gift-cards',
        'sports-categories',
        'blog',
        'users',
        'customers',
        'settings',
        'invoices',
        'audit-logs',
        'locations',
        'roles-permissions',
    ],

    'module_labels' => [
        'dashboard'         => 'Dashboard',
        'calendar'          => 'Calendar',
        'bookings'          => 'Bookings',
        'payments'          => 'Payments',
        'venues'            => 'Venues',
        'listings'          => 'Listings',
        'special-days'      => 'Special Days',
        'resources'         => 'Resources',
        'add-ons'           => 'Add-ons',
        'promocodes'        => 'Promo Codes',
        'loyalty'           => 'Loyalty',
        'gift-cards'        => 'Gift Cards',
        'sports-categories' => 'Sports Categories',
        'blog'              => 'Blog',
        'users'             => 'Users',
        'customers'         => 'Customers',
        'settings'          => 'Settings',
        'invoices'          => 'Invoices',
        'audit-logs'        => 'Audit Logs',
        'locations'         => 'Locations',
        'roles-permissions' => 'Roles & Permissions',
    ],

    'standard_actions' => [
        'view',
        'create',
        'update',
        'delete',
    ],

    'action_labels' => [
        'view'              => 'View',
        'create'            => 'Add',
        'update'            => 'Edit',
        'delete'            => 'Delete',
        'cancel'            => 'Cancel',
        'confirm'           => 'Confirm',
        'reschedule'        => 'Reschedule',
        'complete'          => 'Complete',
        'export'            => 'Export',
        'generate_invoice'  => 'Generate Invoice',
        'refund'            => 'Refund',
        'mark_received'     => 'Mark Received',
        'mark_paid'         => 'Mark as Paid',
        'generate_link'     => 'Generate Payment Link',
        'download_invoice'  => 'Download Invoice',
        'download'          => 'Download',
        'publish'           => 'Publish',
        'sort'              => 'Sort',
        'reorder'           => 'Reorder',
        'manage_images'     => 'Manage Images',
        'bulk_create'       => 'Bulk Create',
        'validate'          => 'Validate',
        'apply'             => 'Apply',
        'remove'            => 'Remove',
        'manage_types'      => 'Manage Types',
        'manage_categories' => 'Manage Categories',
        'manage_roles'      => 'Manage Roles',
        'approve'           => 'Approve',
        'reject'            => 'Reject',
        'manage_tags'       => 'Manage Tags',
        'manage_status'     => 'Manage Status',
    ],

    /*
    |--------------------------------------------------------------------------
    | Full per-module action catalog aligned with admin panel API routes.
    |--------------------------------------------------------------------------
    */
    'module_actions' => [
        'dashboard' => [
            'view',
        ],

        'calendar' => [
            'view',
        ],

        'bookings' => [
            'view',
            'create',
            'update',
            'delete',
            'cancel',
            'confirm',
            'reschedule',
            'complete',
            'export',
            'generate_invoice',
        ],

        'payments' => [
            'view',
            'create',
            'update',
            'delete',
            'confirm',
            'refund',
            'export',
            'mark_received',
            'mark_paid',
            'generate_link',
            'download_invoice',
        ],

        'venues' => [
            'view',
            'create',
            'update',
            'delete',
            'sort',
            'reorder',
        ],

        'listings' => [
            'view',
            'create',
            'update',
            'delete',
            'publish',
            'sort',
            'manage_images',
        ],

        'special-days' => [
            'view',
            'create',
            'update',
            'delete',
            'bulk_create',
        ],

        'resources' => [
            'view',
            'create',
            'update',
            'delete',
        ],

        'add-ons' => [
            'view',
            'create',
            'update',
            'delete',
            'sort',
        ],

        'promocodes' => [
            'view',
            'create',
            'update',
            'delete',
            'validate',
            'apply',
            'remove',
        ],

        'loyalty' => [
            'view',
            'update',
        ],

        'gift-cards' => [
            'view',
            'create',
            'update',
            'delete',
        ],

        'sports-categories' => [
            'view',
            'create',
            'update',
            'delete',
            'sort',
            'manage_types',
        ],

        'blog' => [
            'view',
            'create',
            'update',
            'delete',
            'publish',
            'manage_categories',
        ],

        'users' => [
            'view',
            'create',
            'update',
            'delete',
            'manage_roles',
            'approve',
            'reject',
        ],

        'customers' => [
            'view',
            'create',
            'update',
            'delete',
            'export',
            'manage_tags',
            'manage_status',
        ],

        'settings' => [
            'view',
            'update',
        ],

        'invoices' => [
            'view',
            'create',
            'export',
            'download',
        ],

        'audit-logs' => [
            'view',
            'export',
        ],

        'locations' => [
            'view',
            'create',
            'update',
            'delete',
        ],

        'roles-permissions' => [
            'view',
            'create',
            'update',
            'delete',
        ],
    ],

    'restrictions' => [
        'cannot_manage_super_admin',
        'view_only',
        'view_and_create_only',
        'no_financial_data',
        'verification_view_only',
        'read_only',
    ],

    'defaults' => [
        'super_admin' => [
            'permissions'      => 'all',
            'venue_restricted' => false,
        ],

        'admin' => [
            'permissions' => [
                'dashboard'         => ['view'],
                'calendar'          => ['view'],
                'bookings'          => ['view', 'create', 'update', 'delete', 'cancel', 'confirm', 'reschedule', 'complete', 'export', 'generate_invoice'],
                'payments'          => ['view', 'create', 'update', 'delete', 'confirm', 'refund', 'export', 'mark_received', 'mark_paid', 'generate_link', 'download_invoice'],
                'venues'            => ['view', 'create', 'update', 'delete', 'sort', 'reorder'],
                'listings'          => ['view', 'create', 'update', 'delete', 'publish', 'sort', 'manage_images'],
                'special-days'      => ['view', 'create', 'update', 'delete', 'bulk_create'],
                'resources'         => ['view', 'create', 'update', 'delete'],
                'add-ons'           => ['view', 'create', 'update', 'delete', 'sort'],
                'promocodes'        => ['view', 'create', 'update', 'delete', 'validate', 'apply', 'remove'],
                'loyalty'           => ['view', 'update'],
                'gift-cards'        => ['view', 'create', 'update', 'delete'],
                'sports-categories' => ['view', 'create', 'update', 'delete', 'sort', 'manage_types'],
                'blog'              => ['view', 'create', 'update', 'delete', 'publish', 'manage_categories'],
                'users'             => ['view', 'create', 'update', 'delete', 'approve', 'reject'],
                'customers'         => ['view', 'create', 'update', 'delete', 'export', 'manage_tags', 'manage_status'],
                'invoices'          => ['view', 'create', 'export', 'download'],
            ],
            'venue_restricted' => false,
            'restrictions'     => [
                'users'      => 'cannot_manage_super_admin',
                'settings'   => 'view_only',
                'audit-logs' => 'view_only',
            ],
        ],

        'senior_employee' => [
            'permissions' => [
                'dashboard'  => ['view'],
                'calendar'   => ['view'],
                'bookings'   => ['view', 'create', 'update', 'cancel', 'confirm'],
                'customers'  => ['view', 'create', 'update', 'manage_tags'],
                'payments'   => ['view', 'download_invoice'],
                'promocodes' => ['view', 'create', 'update', 'validate'],
                'loyalty'    => ['view', 'update'],
            ],
            'venue_restricted' => true,
            'restrictions'     => [
                'venues'   => 'view_only',
                'listings' => 'view_only',
                'payments' => 'view_only',
            ],
        ],

        'junior_employee' => [
            'permissions' => [
                'calendar'  => ['view'],
                'bookings'  => ['view', 'create', 'update'],
                'customers' => ['view', 'create'],
            ],
            'venue_restricted' => true,
            'restrictions'     => [
                'customers' => 'view_and_create_only',
                'bookings'  => 'no_financial_data',
            ],
        ],

        'guard' => [
            'permissions' => [
                'calendar' => ['view'],
                'bookings' => ['view'],
            ],
            'venue_restricted' => true,
            'restrictions'     => [
                'bookings' => 'verification_view_only',
                'calendar' => 'read_only',
            ],
        ],

        'customer' => [
            'permissions' => [
                'calendar' => ['view'],
                'bookings' => ['view'],
            ],
            'venue_restricted' => true,
            'restrictions'     => [
                'bookings' => 'verification_view_only',
                'calendar' => 'read_only',
            ],
        ],

        /** Business owner — ops for their own business (not platform admin modules). */
        'provider_admin' => [
            'permissions' => [
                'dashboard'    => ['view'],
                'calendar'     => ['view'],
                'bookings'     => ['view', 'create', 'update', 'delete', 'cancel', 'confirm', 'reschedule', 'complete', 'export', 'generate_invoice'],
                'payments'     => ['view', 'create', 'update', 'confirm', 'export', 'mark_received', 'mark_paid', 'generate_link', 'download_invoice'],
                'venues'       => ['view', 'create', 'update', 'delete', 'sort', 'reorder'],
                'listings'     => ['view', 'create', 'update', 'delete', 'publish', 'sort', 'manage_images'],
                'special-days' => ['view', 'create', 'update', 'delete', 'bulk_create'],
                'resources'    => ['view', 'create', 'update', 'delete'],
                'add-ons'      => ['view', 'create', 'update', 'delete', 'sort'],
                'promocodes'   => ['view', 'create', 'update', 'delete', 'validate', 'apply', 'remove'],
                'customers'    => ['view', 'create', 'update', 'export', 'manage_tags', 'manage_status'],
                'invoices'     => ['view', 'create', 'export', 'download'],
                'settings'     => ['view', 'update'],
                'locations'    => ['view', 'create', 'update', 'delete'],
            ],
            'venue_restricted' => true,
            'restrictions'     => [],
        ],
    ],

    'meta_permissions' => [
        'venue_restricted' => 'meta.venue_restricted',
    ],

];
