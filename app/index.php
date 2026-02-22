<?php
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$scriptDir = str_replace('\\', '/', dirname($scriptName));
if ($scriptDir === '.' || $scriptDir === '') {
    $scriptDir = '';
} elseif ($scriptDir !== '/') {
    $scriptDir = '/' . ltrim($scriptDir, '/');
    $scriptDir = rtrim($scriptDir, '/');
} else {
    $scriptDir = '/';
}
$basename = $scriptDir === '' ? '/' : $scriptDir;
$prefix = $basename === '/' ? '/' : $basename . '/';
$assetPrefix = $prefix;
$rootForApi = $basename === '/' ? '/' : rtrim(dirname($basename), '/');
if ($rootForApi === '' || $rootForApi === '.') {
    $rootForApi = '/';
}
$apiPath = $rootForApi === '/' ? '/api' : $rootForApi . '/api';
$forwardedProto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
if (is_string($forwardedProto) && $forwardedProto !== '') {
    $scheme = explode(',', $forwardedProto)[0];
} elseif (!empty($_SERVER['REQUEST_SCHEME'])) {
    $scheme = $_SERVER['REQUEST_SCHEME'];
} else {
    $https = $_SERVER['HTTPS'] ?? '';
    $scheme = (!empty($https) && $https !== 'off') ? 'https' : 'http';
}
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$apiUrl = rtrim($scheme . '://' . $host, '/') . $apiPath;
$config = [
    'basename' => $basename,
    'prefix' => $prefix,
    'apiUrl' => $apiUrl,
    'assetPrefix' => $assetPrefix,
];
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta
      name="viewport"
      content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no"
    />
    <title>Mirza Web App</title>
    <base href="<?php echo htmlspecialchars($prefix, ENT_QUOTES); ?>" />
    <script src="<?php echo htmlspecialchars($assetPrefix . 'js/telegram-web-app.js', ENT_QUOTES); ?>"></script>
    <script>
      window.__APP_CONFIG__ = <?php echo json_encode($config, JSON_UNESCAPED_SLASHES); ?>;
    </script>
    <script type="module" crossorigin src="<?php echo htmlspecialchars($assetPrefix . 'assets/index-C-2a0Dur.js', ENT_QUOTES); ?>"></script>
    <link rel="modulepreload" crossorigin href="<?php echo htmlspecialchars($assetPrefix . 'assets/vendor-CIGJ9g2q.js', ENT_QUOTES); ?>">
    <link rel="stylesheet" crossorigin href="<?php echo htmlspecialchars($assetPrefix . 'assets/index-BoHBsj0Z.css', ENT_QUOTES); ?>">
  </head>
  <body>
    <div id="root"></div>
    <script>
      (function () {
        const fallbackDarkColor = '#171717';
        const fallbackLightColor = '#FAFAFA';

        const getTelegram = () =>
          window.Telegram && window.Telegram.WebApp
            ? window.Telegram.WebApp
            : null;

        let tg = getTelegram();

        const getDarkColor = () => fallbackDarkColor;

        const isDarkMode = () => {
          if (tg && typeof tg.colorScheme === 'string') {
            return tg.colorScheme === 'dark';
          }
          if (window.matchMedia) {
            try {
              return window.matchMedia('(prefers-color-scheme: dark)').matches;
            } catch (error) {
              console.warn('Dark mode detection error:', error);
            }
          }
          return document.documentElement.classList.contains('dark');
        };

        const setContainerBackground = (color) => {
          document.documentElement.style.backgroundColor = color;
          document.body.style.backgroundColor = color;
          const root = document.getElementById('root');
          if (root) {
            root.style.backgroundColor = color;
          }
        };

        const applyColors = () => {
          if (isDarkMode()) {
            const darkColor = getDarkColor();
            setContainerBackground(darkColor);
            if (tg) {
              try {
                if (typeof tg.setHeaderColor === 'function') {
                  tg.setHeaderColor(darkColor);
                }
                if (typeof tg.setBackgroundColor === 'function') {
                  tg.setBackgroundColor(darkColor);
                }
              } catch (error) {
                console.warn('Telegram color update error:', error);
              }
            }
          } else {
            setContainerBackground(fallbackLightColor);
            if (tg) {
              try {
                if (typeof tg.setHeaderColor === 'function') {
                  tg.setHeaderColor(fallbackLightColor);
                }
                if (typeof tg.setBackgroundColor === 'function') {
                  tg.setBackgroundColor(fallbackLightColor);
                }
              } catch (error) {
                console.warn('Telegram color reset error:', error);
              }
            }
          }
        };

        const triggerVibration = () => {
          try {
            if (
              tg &&
              tg.HapticFeedback &&
              typeof tg.HapticFeedback.impactOccurred === 'function'
            ) {
              tg.HapticFeedback.impactOccurred('medium');
            } else if (navigator.vibrate) {
              navigator.vibrate(200);
            }
          } catch (error) {
            console.log('Vibration error:', error);
          }
        };

        const handleButtonInteraction = (event) => {
          const target = event.target;
          if (!target || typeof target.closest !== 'function') {
            return;
          }
          const buttonLike = target.closest('button, [role="button"], .button');
          if (buttonLike) {
            triggerVibration();
          }
        };

        const ready = () => {
          if (tg) {
            try {
              if (typeof tg.ready === 'function') {
                tg.ready();
              }
              if (typeof tg.expand === 'function') {
                tg.expand();
              }
            } catch (error) {
              console.warn('Telegram readiness error:', error);
            }
            if (typeof tg.onEvent === 'function') {
              tg.onEvent('themeChanged', applyColors);
            }
          }
          applyColors();
        };

        const init = () => {
          document.addEventListener('click', handleButtonInteraction, true);
          ready();
        };

        if (!tg) {
          const intervalId = window.setInterval(() => {
            tg = getTelegram();
            if (tg) {
              window.clearInterval(intervalId);
              init();
            }
          }, 100);
          window.setTimeout(() => {
            if (!tg) {
              window.clearInterval(intervalId);
              init();
            }
          }, 2000);
        } else {
          init();
        }

        if (window.matchMedia) {
          const mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');
          if (typeof mediaQuery.addEventListener === 'function') {
            mediaQuery.addEventListener('change', applyColors);
          } else if (typeof mediaQuery.addListener === 'function') {
            mediaQuery.addListener(applyColors);
          }
        }
      })();
    </script>
  </body>
</html>
