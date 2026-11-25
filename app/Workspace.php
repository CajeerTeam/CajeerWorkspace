<?php
declare(strict_types=1);

namespace Workspace;

use PDO;

function createProject(PDO $db, array $user, string $key, array $form): int {
    $user=organizationUser($db,$user);
    permit($user, 'manage');
    $args = [$user['organization_id'],word($form['title'] ?? '',120,'Название'), word($form['client'] ?? '',120,'Заказчик',false), choice($form['kind'] ?? '',PROJECT_KINDS)];
    return action($db,$user,$key,'project.create',$args,static function () use ($db,$args,$user) {
        $id=insertId($db,'INSERT INTO projects(organization_id,title,client,kind) VALUES(?,?,?,?)',$args);
        query($db,'INSERT INTO project_members(organization_id,project_id,user_id) VALUES(?,?,?)',[$user['organization_id'],$id,$user['id']]);
        return $id;
    });
}

function createTask(PDO $db, array $user, string $key, int $projectId, array $form): int {
    $user=organizationUser($db,$user);
    requireProjectRight($db,$user,$projectId,'manage');
    $data=taskPlanData($form);
    return action($db,$user,$key,'task.create',[$projectId,...$data],static function () use ($db,$data,$projectId,$user) {
        requireProjectRight($db,$user,$projectId,'manage');
        [$title,$assignee,$due,$priority,$customer,$criteria,$milestone]=$data;
        checkTaskAssignee($db,$user,$projectId,$assignee);
        requireOpenMilestone($db,$projectId,$milestone);
        $id=insertId($db,'INSERT INTO tasks(project_id,title,assignee_id,due_on,priority,result_customer,acceptance_criteria) VALUES(?,?,?,?,?,?,?)',[$projectId,$title,$assignee,$due,$priority,$customer,$criteria]);
        setTaskMilestone($db,$projectId,$id,$milestone);
        return $id;
    });
}

function changeTask(PDO $db, array $user, string $key, int $taskId, int $version, string $state): int {
    $user=organizationUser($db,$user);
    choice($state,STATES);
    requireProjectRight($db,$user,(int)planningTask($db,$user,$taskId)['project_id'],'work');
    return action($db,$user,$key,'task.state',[$taskId,$version,$state],static function () use ($db,$user,$taskId,$version,$state) {
        $task = entity($db,'SELECT * FROM tasks WHERE id=?',[$taskId]);
        requireProjectRight($db,$user,(int)$task['project_id'],'work');
        if (!roleAllows(projectRole($db,$user,(int)$task['project_id']),'manage') && (int)$task['assignee_id'] !== (int)$user['id']) throw new Problem('Менять статус может исполнитель или менеджер.',403);
        if ((int)$task['version'] !== $version) throw new Problem('Задача уже изменилась. Обновите страницу проекта.',409);
        if ($task['state']===$state) return $taskId;
        checkTaskTransition($db,$task,$state);
        query($db,'UPDATE tasks SET state=?,version=version+1 WHERE id=?',[$state,$taskId]);
        return $taskId;
    });
}

function recordCash(PDO $db,array $user,string $key,int $projectId,array $form): int {
    $p=submitPayment($db,$user,$key,$projectId,paymentData($form));
    return (int)($p['cash_entry_id']??$p['id']);
}
function reverseCash(PDO $db,array $user,string $key,int $id): int {
    $project=(int)entity($db,'SELECT project_id FROM cash_entries WHERE id=?',[$id])['project_id'];
    $p=submitPayment($db,$user,$key,$project,[],$id);
    return (int)($p['cash_entry_id']??$p['id']);
}

function cashFilter(PDO $db,array $user,array $input): array {
    $user=organizationUser($db,$user);
    if (!hasProjectRight($db,$user,'finance') && !can($user,'finance')) throw new Problem('Нет доступа к финансам.',403);
    [$scope,$args]=projectRightScope($db,$user,'finance');
    $clauses = ["e.project_id IN (SELECT p.id FROM projects p WHERE $scope)"];
    if (($input['project'] ?? '') !== '') {
        requireProjectRight($db,$user,numberId($input['project']),'finance');
        $clauses[] = 'e.project_id = ?';
        $args[] = numberId($input['project']);
    }
    $from = calendarDate($input['from'] ?? '',true);
    $to = calendarDate($input['to'] ?? '',true);
    if ($from && $to && $from > $to) throw new Problem('Начало периода позже его окончания.');
    if ($from) { $clauses[] = 'e.booked_on >= ?'; $args[] = $from; }
    if ($to) { $clauses[] = 'e.booked_on <= ?'; $args[] = $to; }
    return [$clauses ? ' WHERE '.implode(' AND ',$clauses) : '', $args];
}

function ledger(PDO $db, array $user,array $input, ?int $offset = null): \PDOStatement {
    [$where,$args] = cashFilter($db,$user,$input);
    $sql = 'SELECT e.*,p.title project_title,u.name actor_name,r.id reversed_by FROM cash_entries e JOIN projects p ON p.id=e.project_id
        JOIN users u ON u.id=e.actor_id LEFT JOIN cash_entries r ON r.reversal_of=e.id' . $where . ' ORDER BY e.booked_on DESC,e.id DESC';
    if ($offset !== null) $sql .= ' LIMIT 51 OFFSET '.max(0,$offset);
    return query($db,$sql,$args);
}

function balances(PDO $db, array $user,array $input): array {
    [$where,$args] = cashFilter($db,$user,$input);
    $rows=query($db,"SELECT currency,
        SUM(CASE WHEN direction='income' THEN amount_minor ELSE 0 END) income,
        SUM(CASE WHEN direction='expense' THEN amount_minor ELSE 0 END) expense
        FROM cash_entries e" . $where . ' GROUP BY currency ORDER BY currency',$args)->fetchAll();
    foreach ($rows as &$row) {
        foreach (['income','expense'] as $key) {
            $n=filter_var($row[$key],FILTER_VALIDATE_INT);
            if ($n===false) throw new Problem('Сумма превышает диапазон отчёта. Сузьте период.',422);
            $row[$key]=$n;
        }
        if (!is_int($row['income']-$row['expense']) || $row['income']-$row['expense']===PHP_INT_MIN || $row['income']===PHP_INT_MIN || $row['expense']===PHP_INT_MIN) throw new Problem('Сумма превышает диапазон отчёта. Сузьте период.',422);
    }
    unset($row); return $rows;
}

function documentFile(array $upload): string {
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'] ?? '')) {
        throw new Problem('Выберите PDF размером до 2 МиБ. Проверьте лимит загрузки PHP.');
    }
    $bytes = file_get_contents($upload['tmp_name'],false,null,0,2097153);
    if ($bytes === false) throw new Problem('Не удалось прочитать файл.');
    return $bytes;
}

function storeDocument(PDO $db, array $user, string $key, int $projectId, ?int $docId, int $expected, array $form, string $bytes): int {
    $user=organizationUser($db,$user);
    requireProjectRight($db,$user,$projectId,'legal.write');
    if (strlen($bytes) > 2097152 || !str_starts_with($bytes,'%PDF-') || !str_contains(substr($bytes,-1024),'%%EOF')) {
        throw new Problem('Нужен PDF до 2 МиБ с корректными маркерами начала и конца.');
    }
    $title = $docId ? '' : word($form['title'] ?? '',150,'Название документа');
    $kind = $docId ? '' : choice($form['kind'] ?? '', DOC_KINDS);
    $hash = hash('sha256',$bytes);
    allowedProject($db,$user,$projectId);
    return action($db,$user,$key,'document.version',[$projectId,$docId,$expected,$title,$kind,$hash],static function () use ($db,$user,$projectId,$docId,$expected,$title,$kind,$bytes,$hash) {
        requireProjectRight($db,$user,$projectId,'legal.write');
        if ($docId === null) {
            $docId=insertId($db,'INSERT INTO documents(project_id,title,kind) VALUES(?,?,?)',[$projectId,$title,$kind]);
        } else {
            entity($db,'SELECT id FROM documents WHERE id=? AND project_id=?',[$docId,$projectId]);
        }
        $latest = query($db,'SELECT revision,sha256 FROM document_versions WHERE document_id=? ORDER BY revision DESC LIMIT 1',[$docId])->fetch();
        $current = (int)($latest['revision'] ?? 0);
        if ($current !== $expected) throw new Problem('У документа появилась новая версия. Откройте историю заново.',409);
        if ($latest && $latest['sha256'] === $hash) throw new Problem('Этот файл уже является текущей версией.',409);
        if (isPostgres($db)) { query($db,"INSERT INTO document_versions(document_id,revision,sha256,bytes,actor_id) VALUES(?,?,?,decode(?,'hex'),?)",[$docId,$current+1,$hash,bin2hex($bytes),$user['id']]); return $docId; }
        $stmt = $db->prepare('INSERT INTO document_versions(document_id,revision,sha256,bytes,actor_id) VALUES(?,?,?,?,?)');
        $stmt->bindValue(1,$docId,PDO::PARAM_INT);
        $stmt->bindValue(2,$current+1,PDO::PARAM_INT);
        $stmt->bindValue(3,$hash);
        $stmt->bindValue(4,$bytes,PDO::PARAM_LOB);
        $stmt->bindValue(5,$user['id'],PDO::PARAM_INT);
        $stmt->execute();
        return $docId;
    });
}

function linkConnection(PDO $db, array $user, string $key, int $projectId, string $connection, bool $attach): int {
    $user=organizationUser($db,$user);
    requireProjectRight($db,$user,$projectId,'operations');
    if (!isset(connections()[$connection]) && $attach) throw new Problem('Подключение отсутствует в конфигурации.');
    allowedProject($db,$user,$projectId);
    return action($db,$user,$key,$attach?'connection.attach':'connection.detach',[$projectId,$connection],static function () use ($db,$projectId,$connection,$attach,$user) {
        allowedProject($db,$user,$projectId);
        requireProjectRight($db,$user,$projectId,'operations');
        if ($attach && organizationUser($db,$user)['role']!=='owner' && !isset(scopedConnections($db,$user)[$connection])) throw new Problem('Подключение не предоставлено оператору.',403);
        if (!connectionAllowed($db,$user,$connection)) throw new Problem('Подключение не найдено.',404);
        query($db,$attach ? 'INSERT INTO project_connections VALUES(?,?) ON CONFLICT DO NOTHING' : 'DELETE FROM project_connections WHERE project_id=? AND connection_key=?',[$projectId,$connection]);
        return $projectId;
    });
}
