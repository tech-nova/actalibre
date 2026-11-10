<?php
declare(strict_types=1);
// Ancienne adresse : ne jamais rejouer son POST sur le nouveau formulaire.
header('Location: ../install.php', true, 303);
exit;
