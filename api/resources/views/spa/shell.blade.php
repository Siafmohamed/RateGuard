{{--
    Development fallback shell. Used ONLY when the built SPA file
    (config('rateguard.spa.index_path')) does not exist yet - i.e. before a
    production build, or when no SPA has been built.

    It renders a minimal document and boots the Vite dev server at
    config('rateguard.spa.dev_url') directly. In production Laravel instead
    injects the same head/body fragments into the built React index.html
    (see App\Domain\Seo\SpaShell); this file is never used then.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
@include('seo.head', ['meta' => $meta])
</head>
<body>
<div id="root"></div>
@include('seo.body', [
    'meta' => $meta,
    'contentHtml' => $contentHtml,
    'list' => $list,
    'initialContent' => $initialContent,
])
<script type="module" src="{{ config('rateguard.spa.dev_url') }}"></script>
</body>
</html>
