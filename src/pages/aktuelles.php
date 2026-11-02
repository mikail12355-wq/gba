<?php require __DIR__ . '/lib/gba.php'; $liste = beitraege(); ?>
<section>
  <div class="wrap">
    <div class="timeline">
      <?php foreach ($liste as $b) { echo beitrag_html($b); } ?>
      <?php if (!$liste): ?><p class="note">Zurzeit gibt es keine Mitteilungen.</p><?php endif; ?>
    </div>
  </div>
</section>
