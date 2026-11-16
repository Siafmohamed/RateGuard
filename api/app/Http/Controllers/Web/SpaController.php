<?php

namespace App\Http\Controllers\Web;

use App\Domain\Seo\SeoMeta;
use App\Domain\Seo\SpaShell;
use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

/**
 * The SPA catch-all.
 *
 * Any GET that is not an API call, a Phase 5 SEO route or a static asset is a
 * CLIENT route: Laravel returns the shell (200) so a hard refresh on
 * /app/invoices/12 gets a real document and React can boot over it.
 *
 * The head is noindex: client routes are private or dynamic, and they must not
 * compete with the indexable /blog and /docs pages. The response is never
 * cached - it is replaced by the SPA as soon as the bundle runs.
 */
class SpaController extends Controller
{
    public function __construct(
        private readonly SpaShell $shell,
    ) {}

    public function show(): Response
    {
        $response = $this->shell->render(SeoMeta::forApp());

        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
