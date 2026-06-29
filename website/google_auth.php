<?php
/** Start Google OAuth: redirect the visitor to Google's consent screen. */
require __DIR__ . '/includes/bootstrap.php';

$cid = (string) cfg('GOOGLE_CLIENT_ID', '');
if ($cid === '') redirect('login.php');

if (!empty($_GET['next'])) $_SESSION['next'] = $_GET['next'];

$state = bin2hex(random_bytes(16));
$_SESSION['g_state'] = $state;

$redirect = (string) cfg('GOOGLE_REDIRECT', rtrim((string) cfg('PUBLIC_URL', 'https://saathi.neermedia.com'), '/') . '/google_callback.php');

$q = http_build_query([
    'client_id'              => $cid,
    'redirect_uri'           => $redirect,
    'response_type'          => 'code',
    'scope'                  => 'openid email profile',
    'state'                  => $state,
    'access_type'            => 'online',
    'prompt'                 => 'select_account',
    'include_granted_scopes' => 'true',
]);
redirect('https://accounts.google.com/o/oauth2/v2/auth?' . $q);
