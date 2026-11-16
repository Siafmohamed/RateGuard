<?php

use App\Http\Controllers\Web\SeoController;
use App\Http\Controllers\Web\SpaController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| SEO shell (public)
|--------------------------------------------------------------------------
|
| Laravel serves real HTML for /blog and /docs so crawlers and no-JS clients
| see a proper <head> and content; the React SPA then mounts over it. No auth
| and no tenant middleware: this is public content, not tenant data. Throttled
| because these routes fan out to the (cached) content gateway.
|
*/
Route::middleware('throttle:120,1')->group(function () {
    Route::get('/blog', [SeoController::class, 'posts'])->name('seo.blog');

    // TODO: docs are WordPress *pages* for now; swap to a "docs" CPT later.
    Route::get('/docs/{slug}', [SeoController::class, 'page'])
        ->where('slug', '[a-z0-9-]+')
        ->name('seo.page');

    Route::get('/blog/{slug}', [SeoController::class, 'post'])
        ->where('slug', '[a-z0-9-]+')
        ->name('seo.post');

    Route::get('/sitemap.xml', [SeoController::class, 'sitemap'])->name('seo.sitemap');
    Route::get('/robots.txt', [SeoController::class, 'robots'])->name('seo.robots');
});

/*
|--------------------------------------------------------------------------
| SPA shell (client routes)
|--------------------------------------------------------------------------
|
| Everything that is not an API call, a Phase 5 SEO route, a webhook or a
| static asset is a CLIENT route owned by the React SPA. Laravel returns the
| shell with a 200 so a hard refresh (or any no-JS client) gets a real
| document; React then boots over it with createRoot(). The head is noindex
| (see App\Domain\Seo\SeoMeta::forApp) so these routes never compete in search
| results with the indexable /blog and /docs pages.
|
| The Phase 5 routes above win by registration order. The negative lookahead
| keeps the other reserved prefixes out of the catch-all entirely, so /api/*,
| /sanctum/*, /horizon/*, /stripe/*, /up, /assets/*, /favicon.svg,
| /sitemap.xml and /robots.txt keep resolving as themselves instead of
| returning HTML.
|
*/
Route::get('/', [SpaController::class, 'show'])->name('spa.home');

Route::get('/{any}', [SpaController::class, 'show'])
    ->where('any', '^(?!api(?:/|$)|sanctum(?:/|$)|horizon(?:/|$)|stripe(?:/|$)|up$|blog(?:/|$)|docs(?:/|$)|assets(?:/|$)|favicon\.svg$|sitemap\.xml$|robots\.txt$).*$')
    ->name('spa');
