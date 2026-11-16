{{--
    <head> tags for the SEO shell. Injected before </head> by App\Domain\Seo\SpaShell.

    Security: every dynamic value goes through Blade's {{ }} escaping. The
    JSON-LD block is built by App\Domain\Seo\JsonScript (json_encode with the
    HEX flags) and returned as an HtmlString, so it contains no raw <, >, &, '
    or " in its content and passes through {{ }} verbatim - content like
    "</script><script>" can never break out of the <script> element.
--}}
<title>{{ $meta->title }}</title>
@if ($meta->description)
<meta name="description" content="{{ $meta->description }}">
@endif
<meta name="robots" content="{{ $meta->robots }}">
@if ($meta->canonical)
<link rel="canonical" href="{{ $meta->canonical }}">
@endif

<meta property="og:site_name" content="{{ $meta->siteName }}">
<meta property="og:type" content="{{ $meta->ogType }}">
<meta property="og:title" content="{{ $meta->ogTitle }}">
@if ($meta->description)
<meta property="og:description" content="{{ $meta->description }}">
@endif
@if ($meta->canonical)
<meta property="og:url" content="{{ $meta->canonical }}">
@endif
@if ($meta->ogImage)
<meta property="og:image" content="{{ $meta->ogImage }}">
@endif
@if ($meta->ogType === 'article' && $meta->publishedAt)
<meta property="article:published_time" content="{{ $meta->publishedAt }}">
@endif
@if ($meta->ogType === 'article' && $meta->modifiedAt)
<meta property="article:modified_time" content="{{ $meta->modifiedAt }}">
@endif

<meta name="twitter:card" content="{{ $meta->ogImage ? 'summary_large_image' : 'summary' }}">
<meta name="twitter:title" content="{{ $meta->ogTitle }}">
@if ($meta->description)
<meta name="twitter:description" content="{{ $meta->description }}">
@endif
@if ($meta->ogImage)
<meta name="twitter:image" content="{{ $meta->ogImage }}">
@endif

@if ($meta->jsonLd)
<script type="application/ld+json">{{ \App\Domain\Seo\JsonScript::from($meta->jsonLd) }}</script>
@endif
