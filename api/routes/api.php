<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BillingController;
use App\Http\Controllers\Api\V1\CarrierController;
use App\Http\Controllers\Api\V1\ContentController;
use App\Http\Controllers\Api\V1\InvoiceController;
use App\Http\Controllers\Api\V1\OrganizationController;

Route::prefix('v1')->group(function () {

    // Health Check
    Route::get('/health', function () {
        $checks = [];

        try {
            DB::select('select 1');
            $checks['database'] = 'ok';
        } catch (\Throwable $e) {
            $checks['database'] = 'fail';
        }

        try {
            Redis::connection()->ping();
            $checks['redis'] = 'ok';
        } catch (\Throwable $e) {
            $checks['redis'] = 'fail';
        }

        $healthy = ! in_array('fail', $checks, true);

        return response()->json([
            'status' => $healthy ? 'ok' : 'degraded',
            'checks' => $checks,
        ], $healthy ? 200 : 503);
    });

    // Login
    Route::post('/auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:5,1');

    // Register
    Route::post('/auth/register', [AuthController::class, 'register'])
        ->middleware('throttle:5,1');

    // Content gateway (headless WordPress). PUBLIC on purpose: no auth and no
    // tenant scoping - the content is not tenant data. Laravel is the only
    // service that talks to WordPress.
    Route::middleware('throttle:60,1')->prefix('content')->group(function () {
        Route::get('/posts', [ContentController::class, 'posts']);
        Route::get('/posts/{slug}', [ContentController::class, 'post']);
        Route::get('/pages/{slug}', [ContentController::class, 'page']);
        Route::get('/categories', [ContentController::class, 'categories']);
    });

    // Protected Routes
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AuthController::class, 'me']);

        // Organizations the user belongs to.
        Route::get('/organizations', [OrganizationController::class, 'index']);

        // Carrier reference data: global, so NOT behind the "org" middleware.
        Route::get('/carriers', [CarrierController::class, 'index']);

        // Routes scoped to the current organization (resolved by the "org" middleware).
        Route::middleware('org')->group(function () {
            Route::get('/org', [OrganizationController::class, 'show']);
            Route::patch('/org', [OrganizationController::class, 'update']);

            // Read endpoints: isolation is enforced by the tenancy global scope.
            Route::get('/invoices', [InvoiceController::class, 'index']);

            // Literal route must be declared before the "/invoices/{invoice}" one.
            Route::post('/invoices/upload', [InvoiceController::class, 'upload']);

            Route::get('/invoices/{invoice}', [InvoiceController::class, 'show']);
            Route::get('/invoices/{invoice}/findings', [InvoiceController::class, 'findings']);
            Route::post('/invoices/{invoice}/audit', [InvoiceController::class, 'audit']);

            // Billing: tenant-scoped like the other organization routes. Reading
            // the overview is open to any member; every mutation is owner-only
            // (enforced by SubscriptionPolicy).
            Route::get('/billing', [BillingController::class, 'index']);
            // Read-only plan catalogue; available to any member like the overview.
            Route::get('/billing/plans', [BillingController::class, 'catalogue']);
            Route::post('/billing/checkout', [BillingController::class, 'checkout']);
            Route::post('/billing/portal', [BillingController::class, 'portal']);
            Route::post('/billing/cancel', [BillingController::class, 'cancel']);
            Route::post('/billing/resume', [BillingController::class, 'resume']);
        });
    });

});
