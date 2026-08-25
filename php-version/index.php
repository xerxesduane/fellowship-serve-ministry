<?php
declare(strict_types=1);

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; font-src 'self'; connect-src 'self'; base-uri 'self'; form-action 'self'");
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#12263f">
  <meta name="description" content="Discover your spiritual gifts, heart, abilities, personality, and experiences for serving at Fellowship Dubai.">
  <meta property="og:title" content="S.H.A.P.E. Discovery | Fellowship Dubai">
  <meta property="og:description" content="A guided S.H.A.P.E. journey for discovering how God has prepared you to serve.">
  <meta property="og:type" content="website">
  <meta property="og:image" content="assets/fellowship-logo.jpeg">
  <title>S.H.A.P.E. Discovery | Fellowship Dubai</title>
  <link rel="manifest" href="manifest.webmanifest">
  <link rel="icon" href="assets/fellowship-logo.jpeg">
  <link rel="stylesheet" href="assets/styles.css">
  <script type="module" src="assets/app.js"></script>
</head>
<body>
  <div id="shape-app" aria-live="polite"></div>
  <noscript>This assessment needs JavaScript to save answers, calculate the profile, and move between steps.</noscript>
</body>
</html>
