<?php
declare(strict_types=1);

date_default_timezone_set('UTC');
umask(0077);
require_once __DIR__ . '/Core.php';
require_once __DIR__ . '/Organizations.php';
require_once __DIR__ . '/Permissions.php';
require_once __DIR__ . '/Payments.php';
require_once __DIR__ . '/Invoices.php';
require_once __DIR__ . '/Accounting.php';
require_once __DIR__ . '/Migrations.php';
require_once __DIR__ . '/Postgres.php';
require_once __DIR__ . '/Planning.php';
require_once __DIR__ . '/Workload.php';
require_once __DIR__ . '/Workspace.php';
require_once __DIR__ . '/Integrations.php';
require_once __DIR__ . '/Oidc.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Clients.php';
require_once __DIR__ . '/Oss.php';
require_once __DIR__ . '/GitHub.php';
require_once __DIR__ . '/Releases.php';
require_once __DIR__ . '/Infrastructure.php';
require_once __DIR__ . '/Deployments.php';
