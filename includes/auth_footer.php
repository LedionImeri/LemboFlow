<?php
/** Closing half of the guest layout. */

$flashes = pull_flashes();
?>
    </div>
  </main>
</div>

<div class="toasts" data-toasts role="region" aria-live="polite" aria-label="Notifications"></div>
<script type="application/json" id="flash-data"><?= json_encode($flashes, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
