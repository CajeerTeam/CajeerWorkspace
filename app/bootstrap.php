<?php
declare(strict_types=1);

date_default_timezone_set('UTC');
umask(0077);
require_once __DIR__ . '/Core.php';
require_once __DIR__ . '/Migrations.php';
require_once __DIR__ . '/Workspace.php';
require_once __DIR__ . '/Integrations.php';
