<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Info(
    version: '1.0.0',
    title: 'EuroDental CRM and Webshop API',
    description: 'API documentation for the separated EuroDental CRM and webshop backend.'
)]
#[OA\Server(
    url: 'http://127.0.0.1:8001',
    description: 'Local API server'
)]
#[OA\SecurityScheme(
    securityScheme: 'sanctum',
    type: 'http',
    description: 'Paste the Sanctum token returned from login. Swagger UI will send it as: Bearer {token}.',
    scheme: 'bearer',
    bearerFormat: 'Sanctum'
)]
#[OA\Tag(name: 'CRM Auth', description: 'CRM user authentication')]
#[OA\Tag(name: 'CRM Clients', description: 'CRM client records and related data')]
#[OA\Tag(name: 'CRM Tasks', description: 'CRM tasks, calendar data, task types, and services')]
#[OA\Tag(name: 'CRM Tracking', description: 'Suivi and tracking statistics')]
#[OA\Tag(name: 'CRM Notifications', description: 'Authenticated CRM notifications')]
#[OA\Tag(name: 'Webshop Auth', description: 'Webshop client authentication')]
#[OA\Tag(name: 'Webshop Products', description: 'Public webshop product catalog')]
#[OA\Schema(
    schema: 'Unauthenticated',
    properties: [
        new OA\Property(property: 'message', type: 'string', example: 'Unauthenticated.'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'ValidationError',
    properties: [
        new OA\Property(property: 'message', type: 'string', example: 'The given data was invalid.'),
        new OA\Property(property: 'errors', type: 'object'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'MessageResponse',
    properties: [
        new OA\Property(property: 'message', type: 'string', example: 'Success.'),
    ],
    type: 'object'
)]
final class EuroDentalApi
{
    #[OA\Post(
        path: '/api/v1/auth/login',
        summary: 'Log in as a CRM user',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email', 'password'],
                properties: [
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'admin@example.com'),
                    new OA\Property(property: 'password', type: 'string', format: 'password', example: 'password'),
                ]
            )
        ),
        tags: ['CRM Auth'],
        responses: [
            new OA\Response(response: 200, description: 'Login successful'),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ]
    )]
    public function crmLogin(): void
    {
    }

    #[OA\Post(path: '/api/v1/auth/forgot-password', summary: 'Request a CRM password reset link', tags: ['CRM Auth'], responses: [new OA\Response(response: 200, description: 'Reset email response')])]
    public function crmForgotPassword(): void
    {
    }

    #[OA\Post(path: '/api/v1/auth/reset-password', summary: 'Reset a CRM user password', tags: ['CRM Auth'], responses: [new OA\Response(response: 200, description: 'Password reset response')])]
    public function crmResetPassword(): void
    {
    }

    #[OA\Get(path: '/api/v1/auth/me', summary: 'Get authenticated CRM user', security: [['sanctum' => []]], tags: ['CRM Auth'], responses: [new OA\Response(response: 200, description: 'Authenticated user'), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/Unauthenticated'))])]
    public function crmMe(): void
    {
    }

    #[OA\Post(path: '/api/v1/auth/logout', summary: 'Log out the authenticated CRM user', security: [['sanctum' => []]], tags: ['CRM Auth'], responses: [new OA\Response(response: 200, description: 'Logout response'), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/Unauthenticated'))])]
    public function crmLogout(): void
    {
    }

    #[OA\Post(
        path: '/api/v1/shop/auth/login',
        summary: 'Log in as a webshop client',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email', 'password'],
                properties: [
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'client@example.com'),
                    new OA\Property(property: 'password', type: 'string', format: 'password', example: 'password'),
                ]
            )
        ),
        tags: ['Webshop Auth'],
        responses: [
            new OA\Response(response: 200, description: 'Login successful'),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ]
    )]
    public function shopLogin(): void
    {
    }

    #[OA\Post(path: '/api/v1/shop/auth/register', summary: 'Register a webshop client', tags: ['Webshop Auth'], responses: [new OA\Response(response: 201, description: 'Client registered'), new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'))])]
    public function shopRegister(): void
    {
    }

    #[OA\Get(path: '/api/v1/shop/auth/me', summary: 'Get authenticated webshop client', security: [['sanctum' => []]], tags: ['Webshop Auth'], responses: [new OA\Response(response: 200, description: 'Authenticated client'), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/Unauthenticated'))])]
    public function shopMe(): void
    {
    }

    #[OA\Post(path: '/api/v1/shop/auth/logout', summary: 'Log out the authenticated webshop client', security: [['sanctum' => []]], tags: ['Webshop Auth'], responses: [new OA\Response(response: 200, description: 'Logout response'), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/Unauthenticated'))])]
    public function shopLogout(): void
    {
    }

    #[OA\Get(path: '/api/v1/shop/products', summary: 'List webshop products', tags: ['Webshop Products'], parameters: [new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1))], responses: [new OA\Response(response: 200, description: 'Product list')])]
    public function shopProducts(): void
    {
    }

    #[OA\Get(path: '/api/v1/shop/products/search', summary: 'Search webshop products', tags: ['Webshop Products'], parameters: [new OA\Parameter(name: 'q', in: 'query', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1))], responses: [new OA\Response(response: 200, description: 'Search results')])]
    public function shopProductSearch(): void
    {
    }

    #[OA\Get(path: '/api/v1/clients', summary: 'List CRM clients', security: [['sanctum' => []]], tags: ['CRM Clients'], parameters: [new OA\Parameter(name: 'search', in: 'query', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1))], responses: [new OA\Response(response: 200, description: 'Client list'), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/Unauthenticated'))])]
    public function clients(): void
    {
    }

    #[OA\Get(path: '/api/v1/clients/{clientId}', summary: 'Show a CRM client', security: [['sanctum' => []]], tags: ['CRM Clients'], parameters: [new OA\Parameter(name: 'clientId', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'Client detail'), new OA\Response(response: 404, description: 'Client not found')])]
    public function clientShow(): void
    {
    }

    #[OA\Get(path: '/api/v1/clients/{clientId}/tasks', summary: 'List tasks for a CRM client', security: [['sanctum' => []]], tags: ['CRM Clients'], parameters: [new OA\Parameter(name: 'clientId', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'Client task list')])]
    public function clientTasks(): void
    {
    }

    #[OA\Get(path: '/api/v1/clients/{clientId}/orders', summary: 'List orders for a CRM client', security: [['sanctum' => []]], tags: ['CRM Clients'], parameters: [new OA\Parameter(name: 'clientId', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'Client order list')])]
    public function clientOrders(): void
    {
    }

    #[OA\Get(path: '/api/v1/clients/{clientId}/warranty-products', summary: 'List warranty products for a CRM client', security: [['sanctum' => []]], tags: ['CRM Clients'], parameters: [new OA\Parameter(name: 'clientId', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'Warranty product list')])]
    public function clientProducts(): void
    {
    }

    #[OA\Get(path: '/api/v1/tasks', summary: 'List CRM tasks', security: [['sanctum' => []]], tags: ['CRM Tasks'], parameters: [new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'search', in: 'query', schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'Task list')])]
    public function tasks(): void
    {
    }

    #[OA\Get(path: '/api/v1/tasks/{id}', summary: 'Show a CRM task', security: [['sanctum' => []]], tags: ['CRM Tasks'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'Task detail'), new OA\Response(response: 404, description: 'Task not found')])]
    public function taskShow(): void
    {
    }

    #[OA\Get(path: '/api/v1/tasks/calendar/month', summary: 'Get monthly task calendar data', security: [['sanctum' => []]], tags: ['CRM Tasks'], parameters: [new OA\Parameter(name: 'year', in: 'query', schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'month', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 12))], responses: [new OA\Response(response: 200, description: 'Monthly calendar data')])]
    public function taskCalendarMonth(): void
    {
    }

    #[OA\Get(path: '/api/v1/tasks/delivered-payments', summary: 'Get delivered payment task data', security: [['sanctum' => []]], tags: ['CRM Tasks'], responses: [new OA\Response(response: 200, description: 'Delivered payment data')])]
    public function deliveredPayments(): void
    {
    }

    #[OA\Get(path: '/api/v1/tasks/types', summary: 'List task types', security: [['sanctum' => []]], tags: ['CRM Tasks'], responses: [new OA\Response(response: 200, description: 'Task type list')])]
    public function taskTypes(): void
    {
    }

    #[OA\Get(path: '/api/v1/services', summary: 'List CRM services', security: [['sanctum' => []]], tags: ['CRM Tasks'], responses: [new OA\Response(response: 200, description: 'Service list'), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/Unauthenticated'))])]
    public function services(): void
    {
    }

    #[OA\Get(path: '/api/v1/tracking/month', summary: 'Get monthly suivi task data', security: [['sanctum' => []]], tags: ['CRM Tracking'], responses: [new OA\Response(response: 200, description: 'Monthly tracking data')])]
    public function trackingMonth(): void
    {
    }

    #[OA\Get(path: '/api/v1/tracking/stats', summary: 'Get suivi statistics', security: [['sanctum' => []]], tags: ['CRM Tracking'], parameters: [new OA\Parameter(name: 'user_id', in: 'query', schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'year', in: 'query', schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'Tracking statistics')])]
    public function trackingStats(): void
    {
    }

    #[OA\Get(path: '/api/v1/tracking/profile', summary: 'Get suivi profile data', security: [['sanctum' => []]], tags: ['CRM Tracking'], responses: [new OA\Response(response: 200, description: 'Tracking profile')])]
    public function trackingProfile(): void
    {
    }

    #[OA\Get(path: '/api/v1/tracking/deployments', summary: 'List suivi deployments', security: [['sanctum' => []]], tags: ['CRM Tracking'], responses: [new OA\Response(response: 200, description: 'Deployment list')])]
    public function deployments(): void
    {
    }

    #[OA\Get(path: '/api/v1/tracking/deployments/{id}', summary: 'Show suivi deployment details', security: [['sanctum' => []]], tags: ['CRM Tracking'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'Deployment details'), new OA\Response(response: 404, description: 'Deployment not found')])]
    public function deploymentDetails(): void
    {
    }

    #[OA\Get(path: '/api/v1/tracking/orders', summary: 'List suivi orders', security: [['sanctum' => []]], tags: ['CRM Tracking'], responses: [new OA\Response(response: 200, description: 'Order list')])]
    public function trackingOrders(): void
    {
    }

    #[OA\Get(path: '/api/v1/tracking/orders/{id}', summary: 'Show suivi order details', security: [['sanctum' => []]], tags: ['CRM Tracking'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'Order details'), new OA\Response(response: 404, description: 'Order not found')])]
    public function orderDetails(): void
    {
    }

    #[OA\Get(path: '/api/v1/tracking/rankings', summary: 'Get suivi rankings', security: [['sanctum' => []]], tags: ['CRM Tracking'], responses: [new OA\Response(response: 200, description: 'Rankings')])]
    public function rankings(): void
    {
    }

    #[OA\Get(path: '/api/v1/tracking/tasks-by-type', summary: 'Get tracking task counts by type', security: [['sanctum' => []]], tags: ['CRM Tracking'], responses: [new OA\Response(response: 200, description: 'Task counts by type')])]
    public function tasksByType(): void
    {
    }

    #[OA\Get(path: '/api/v1/notifications', summary: 'List notifications', security: [['sanctum' => []]], tags: ['CRM Notifications'], responses: [new OA\Response(response: 200, description: 'Notification list')])]
    public function notifications(): void
    {
    }

    #[OA\Get(path: '/api/v1/notifications/unread-count', summary: 'Get unread notification count', security: [['sanctum' => []]], tags: ['CRM Notifications'], responses: [new OA\Response(response: 200, description: 'Unread count')])]
    public function unreadCount(): void
    {
    }

    #[OA\Get(path: '/api/v1/notifications/{id}', summary: 'Show a notification', security: [['sanctum' => []]], tags: ['CRM Notifications'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'Notification detail'), new OA\Response(response: 404, description: 'Notification not found')])]
    public function notificationShow(): void
    {
    }

    #[OA\Put(path: '/api/v1/notifications/{id}/read', summary: 'Mark a notification as read', security: [['sanctum' => []]], tags: ['CRM Notifications'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'Notification updated')])]
    public function notificationRead(): void
    {
    }

    #[OA\Put(path: '/api/v1/notifications/mark-all-read', summary: 'Mark all notifications as read', security: [['sanctum' => []]], tags: ['CRM Notifications'], responses: [new OA\Response(response: 200, description: 'Notifications updated')])]
    public function notificationsReadAll(): void
    {
    }

    #[OA\Delete(path: '/api/v1/notifications/{id}', summary: 'Delete a notification', security: [['sanctum' => []]], tags: ['CRM Notifications'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'Notification deleted'), new OA\Response(response: 404, description: 'Notification not found')])]
    public function notificationDelete(): void
    {
    }
}
