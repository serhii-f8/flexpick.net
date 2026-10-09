{{-- A ReportChartBuilder SVG. Inline on the web; on a PDF dompdf only reads
     SVG through an <img>, so it goes in as a data URI. The SVG is built
     with every label escaped, so emitting it raw is safe. --}}
@if ($pdf ?? false)
    <img src="data:image/svg+xml;base64,{{ base64_encode($svg) }}" style="width: {{ $width ?? '100%' }};" alt="">
@else
    <div class="[&>svg]:h-auto [&>svg]:max-w-full">{!! $svg !!}</div>
@endif
