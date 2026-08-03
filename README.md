# EuroDental API

Stateless Laravel API boundary for the EuroDental CRM and webshop.

- Base path: `/api/v1`
- CRM authentication: Laravel Sanctum personal access tokens
- CRM authorization: existing profile permission codes
- Mobile/PWA task lifecycle and push routes are intentionally excluded
- Media files will be served by the separate media project

## Controller structure

```text
app/Http/Controllers/Api/V1/
├── Crm/
│   ├── AuthController.php
│   ├── ClientController.php
│   ├── NotificationController.php
│   ├── ServiceController.php
│   ├── TaskCalendarController.php
│   ├── TaskController.php
│   ├── TaskTypeController.php
│   └── TrackingController.php
└── Webshop/
    ├── ClientAuthController.php
    └── ProductController.php
```

The project contains only API controllers. Copied web, session-auth, mobile,
push, and legacy controller code has been removed. Future PWA endpoints belong
in the separate mobile API project.

The API shares the existing CRM database schema. Copy `.env.example` to `.env`, configure the existing database, run `composer install`, generate an application key, and inspect the contract with `php artisan route:list --path=api/v1`.
