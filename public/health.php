<?php
declare(strict_types=1);

namespace Workspace;

require dirname(__DIR__).'/app/bootstrap.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
try {
    $db = database();
    $ready = (bool)$db->query("SELECT 1 FROM users WHERE role='owner' AND enabled=1 LIMIT 1")->fetchColumn();
} catch (\Throwable) { $ready = false; }
http_response_code($ready ? 200 : 503);
echo json_encode(['status'=>$ready?'ready':'unavailable']);
