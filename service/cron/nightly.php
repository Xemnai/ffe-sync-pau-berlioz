<?php

declare(strict_types=1);

// Ce fichier sera appelé chaque nuit par OVH.
// Pour le moment, il confirme seulement que le Cron fonctionne.

echo sprintf(
    "[%s] FFE Sync Pau Berlioz : cron opérationnel.\n",
    gmdate('c')
);