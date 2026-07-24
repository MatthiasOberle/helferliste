<?php

declare(strict_types=1);

// Alte Direkt-URL bleibt kompatibel und führt zum gebündelten Exportbereich.
header('Location: export.php?download=notes');
exit;
