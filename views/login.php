<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= esc($appTitle) ?><?= $appStoreNumber ? ' - #' . esc($appStoreNumber) : '' ?></title>
<link rel="stylesheet" href="assets/app.css">
<script>(function(){if(localStorage.getItem('theme')==='dark')document.documentElement.setAttribute('data-theme','dark');})();</script>
</head>
<body>

<div class="login-wrap">
  <div class="login-card">
    <?php if ($appLogo): ?>
      <img src="<?= esc($appLogo) ?>" alt="<?= esc($appBrand) ?>" class="login-logo">
    <?php else: ?>
      <div class="logo"><?= esc($appBrand) ?></div>
    <?php endif; ?>
    <?php if ($appStoreNumber): ?>
      <div class="sub">SS #<?= esc($appStoreNumber) ?></div>
    <?php else: ?>
      <div class="sub"><?= esc($appTitle) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
      <div class="error-msg"><?= esc($error) ?></div>
    <?php endif; ?>

    <form method="post">
      <input type="hidden" name="action" value="login">
      <div class="field">
        <label>Username</label>
        <input type="text" name="username" autofocus autocomplete="username">
      </div>
      <div class="field">
        <label>Password</label>
        <input type="password" name="password" autocomplete="current-password">
      </div>
      <button class="btn btn-full" type="submit">Sign in</button>
    </form>

    <?php if ($isLocalhost): ?>
      <div class="login-dev-sep">or</div>
      <form method="post">
        <input type="hidden" name="action" value="dev_login">
        <button class="btn btn-full login-dev-btn" type="submit">Quick login (localhost)</button>
      </form>
    <?php endif; ?>
  </div>
</div>

</body>
</html>
