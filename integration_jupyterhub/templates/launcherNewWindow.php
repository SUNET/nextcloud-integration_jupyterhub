<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: Mikael Nordin <kano@sunet.se>
// SPDX-License-Identifier: AGPL-3.0-or-later
?>
<div id="content" style="padding: 2em; max-width: 40em; margin: 0 auto;">
  <h2><?php p($l->t('Open shared notebook: %s', [$_['name']])); ?></h2>
  <p><?php p($l->t('Click the button below to open the shared JupyterHub notebook in a new browser tab.')); ?></p>
  <p>
    <a class="button primary"
       href="<?php p($_['launch_url']); ?>"
       target="_blank"
       rel="noopener noreferrer">
      <?php p($l->t('Open in new window')); ?>
    </a>
  </p>
</div>
