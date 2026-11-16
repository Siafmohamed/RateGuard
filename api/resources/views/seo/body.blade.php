{{--
    Server-rendered body for crawlers and no-JS clients, injected right after
    <body ...> by App\Domain\Seo\SpaShell.

    This is NOT SSR hydration: the SPA boots with React createRoot(), which
    REPLACES #seo-content (and #root) once the client bundle runs.

    $contentHtml is the already-sanitised WordPress markup (see
    App\Domain\Content\Support\ContentSanitizer) - the one place a raw
    {!! !!} is permitted. window.__INITIAL_CONTENT__ is built by
    App\Domain\Seo\JsonScript (json_encode with the HEX flags, returned as an
    HtmlString) so it can never break out of the inline <script>.
--}}
<div id="seo-content">
    @if ($contentHtml)
        {!! $contentHtml !!}
    @elseif (! empty($list))
        <ul>
            @foreach ($list as $item)
                <li><a href="{{ $item['url'] }}">{{ $item['title'] }}</a></li>
            @endforeach
        </ul>
    @endif
</div>
<noscript>
    <p>
        <a href="{{ $meta->canonical ?? config('app.url') }}">View this page with JavaScript enabled for the full experience.</a>
    </p>
</noscript>
@if ($initialContent !== null)
<script>window.__INITIAL_CONTENT__ = {{ \App\Domain\Seo\JsonScript::from($initialContent) }};</script>
@endif
