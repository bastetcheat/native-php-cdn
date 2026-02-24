<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CDN Admin Panel</title>
    <meta name="description" content="Self-hosted CDN management panel with file uploads and OAuth tokens">
    <meta name="robots" content="noindex, nofollow">

    <!-- Tailwind CSS v3 -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'system-ui', 'sans-serif'],
                        mono: ['JetBrains Mono', 'monospace'],
                    },
                    colors: {
                        dark: { 900: '#0a0a0f', 800: '#12121a', 700: '#1a1a2e' }
                    }
                }
            }
        }
    </script>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap"
        rel="stylesheet">

    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>

    <!--
        Cache-busting: the ?v= query string is the last-modified timestamp of
        each file, computed server-side so Cloudflare and the browser always
        fetch the latest version after a deploy.
        Inline PHP is used here only for the version stamps.
    -->
    <?php
    $css = __DIR__ . '/styles.css';
    $js = __DIR__ . '/app.js';
    $vCss = file_exists($css) ? filemtime($css) : time();
    $vJs = file_exists($js) ? filemtime($js) : time();
    ?>
    <!-- Custom Styles -->
    <link rel="stylesheet" href="styles.css?v=<?= $vCss ?>">
</head>

<body class="dark">
    <div id="toast-container" class="toast-container"></div>
    <div id="app"></div>
    <script src="app.js?v=<?= $vJs ?>"></script>
</body>

</html>