{{-- Package pages load their typefaces; an embedding host decides for itself (the CSS falls back to system fonts).
     story-studio.fonts = false keeps readers off Google's servers: system fonts, no third-party request. --}}
@if (config('story-studio.fonts', true))
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans:wght@400;500;600&family=Literata:ital,opsz,wght@0,7..72,400;0,7..72,500;1,7..72,400&display=swap" rel="stylesheet">
@endif
