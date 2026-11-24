<?php
declare(strict_types=1);

namespace Workspace;

use PDO;

function project(PDO $db, int $id): array {
    return entity($db, 'SELECT * FROM projects WHERE id=?', [$id]);
}

function createProject(PDO $db, array $user, string $key, array $form): int {
    permit($user, 'manage');
    $args = [word($form['title'] ?? '',120,'Название'), word($form['client'] ?? '',120,'Заказчик',false), choice($form['kind'] ?? '',PROJECT_KINDS)];
    return action($db,$user,$key,'project.create',$args,static function () use ($db,$args) {
        query($db,'INSERT INTO projects(title,client,kind) VALUES(?,?,?)',$args);
        return (int)$db->lastInsertId();
    });
}

function createTask(PDO $db, array $user, string $key, int $projectId, array $form): int {
    permit($user, 'manage');
    $assignee = ($form['assignee'] ?? '') === '' ? null : numberId($form['assignee']);
    $args = [$projectId,word($form['title'] ?? '',180,'Задача'),$assignee,calendarDate($form['due_on'] ?? '',true)];
    return action($db,$user,$key,'task.create',$args,static function () use ($db,$args,$projectId,$assignee) {
        project($db,$projectId);
        if ($assignee !== null) entity($db, "SELECT id FROM users WHERE id=? AND enabled=1 AND role IN ('owner','manager','developer')", [$assignee]);
        query($db,'INSERT INTO tasks(project_id,title,assignee_id,due_on) VALUES(?,?,?,?)',$args);
        return (int)$db->lastInsertId();
    });
}

function changeTask(PDO $db, array $user, string $key, int $taskId, int $version, string $state): int {
    permit($user,'work');
    choice($state,STATES);
    return action($db,$user,$key,'task.state',[$taskId,$version,$state],static function () use ($db,$user,$taskId,$version,$state) {
        $task = entity($db,'SELECT * FROM tasks WHERE id=?',[$taskId]);
        if (!can($user,'manage') && (int)$task['assignee_id'] !== (int)$user['id']) throw new Problem('Менять статус может исполнитель или менеджер.',403);
        if ((int)$task['version'] !== $version) throw new Problem('Задача уже изменилась. Обновите страницу проекта.',409);
        query($db,'UPDATE tasks SET state=?,version=version+1 WHERE id=?',[$state,$taskId]);
        return $taskId;
    });
}

function recordCash(PDO $db, array $user, string $key, int $projectId, array $form): int {
    permit($user,'finance');
    $date = calendarDate($form['booked_on'] ?? '');
    if ($date > gmdate('Y-m-d')) throw new Problem('Для фактического движения денег укажите сегодняшнюю или прошедшую дату.');
    $args = [$projectId,choice($form['direction'] ?? '', DIRECTIONS),moneyMinor($form['amount'] ?? ''),
        choice($form['currency'] ?? '', array_flip(CURRENCIES)),$date,word($form['memo'] ?? '',200,'Назначение'),$user['id']];
    return action($db,$user,$key,'cash.post',$args,static function () use ($db,$args,$projectId) {
        project($db,$projectId);
        query($db,'INSERT INTO cash_entries(project_id,direction,amount_minor,currency,booked_on,memo,actor_id) VALUES(?,?,?,?,?,?,?)',$args);
        return (int)$db->lastInsertId();
    });
}

function reverseCash(PDO $db, array $user, string $key, int $id): int {
    permit($user,'finance');
    return action($db,$user,$key,'cash.reverse',[$id],static function () use ($db,$user,$id) {
        $entry = entity($db,'SELECT * FROM cash_entries WHERE id=?',[$id]);
        if ($entry['reversal_of'] !== null || query($db,'SELECT 1 FROM cash_entries WHERE reversal_of=?',[$id])->fetchColumn()) throw new Problem('Для этой записи сторно уже проведено.',409);
        query($db,'INSERT INTO cash_entries(project_id,direction,amount_minor,currency,booked_on,memo,reversal_of,actor_id) VALUES(?,?,?,?,?,?,?,?)',
            [$entry['project_id'],$entry['direction'],-(int)$entry['amount_minor'],$entry['currency'],gmdate('Y-m-d'),'Сторно · '.$entry['memo'],$id,$user['id']]);
        return (int)$db->lastInsertId();
    });
}

function cashFilter(array $input): array {
    $clauses = [];
    $args = [];
    if (($input['project'] ?? '') !== '') {
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

function ledger(PDO $db, array $input, ?int $offset = null): \PDOStatement {
    [$where,$args] = cashFilter($input);
    $sql = 'SELECT e.*,p.title project_title,u.name actor_name,r.id reversed_by FROM cash_entries e JOIN projects p ON p.id=e.project_id
        JOIN users u ON u.id=e.actor_id LEFT JOIN cash_entries r ON r.reversal_of=e.id' . $where . ' ORDER BY e.booked_on DESC,e.id DESC';
    if ($offset !== null) $sql .= ' LIMIT 51 OFFSET '.max(0,$offset);
    return query($db,$sql,$args);
}

function balances(PDO $db, array $input): array {
    [$where,$args] = cashFilter($input);
    return query($db,"SELECT currency,
        SUM(CASE WHEN direction='income' THEN amount_minor ELSE 0 END) income,
        SUM(CASE WHEN direction='expense' THEN amount_minor ELSE 0 END) expense
        FROM cash_entries e" . $where . ' GROUP BY currency ORDER BY currency',$args)->fetchAll();
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
    permit($user,'legal');
    if (strlen($bytes) > 2097152 || !str_starts_with($bytes,'%PDF-') || !str_contains(substr($bytes,-1024),'%%EOF')) {
        throw new Problem('Нужен PDF до 2 МиБ с корректными маркерами начала и конца.');
    }
    $title = $docId ? '' : word($form['title'] ?? '',150,'Название документа');
    $kind = $docId ? '' : choice($form['kind'] ?? '', DOC_KINDS);
    $hash = hash('sha256',$bytes);
    return action($db,$user,$key,'document.version',[$projectId,$docId,$expected,$title,$kind,$hash],static function () use ($db,$user,$projectId,$docId,$expected,$title,$kind,$bytes,$hash) {
        project($db,$projectId);
        if ($docId === null) {
            query($db,'INSERT INTO documents(project_id,title,kind) VALUES(?,?,?)',[$projectId,$title,$kind]);
            $docId = (int)$db->lastInsertId();
        } else {
            entity($db,'SELECT id FROM documents WHERE id=? AND project_id=?',[$docId,$projectId]);
        }
        $latest = query($db,'SELECT revision,sha256 FROM document_versions WHERE document_id=? ORDER BY revision DESC LIMIT 1',[$docId])->fetch();
        $current = (int)($latest['revision'] ?? 0);
        if ($current !== $expected) throw new Problem('У документа появилась новая версия. Откройте историю заново.',409);
        if ($latest && $latest['sha256'] === $hash) throw new Problem('Этот файл уже является текущей версией.',409);
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
    permit($user,'manage');
    if (!isset(connections()[$connection]) && $attach) throw new Problem('Подключение отсутствует в конфигурации.');
    return action($db,$user,$key,$attach?'connection.attach':'connection.detach',[$projectId,$connection],static function () use ($db,$projectId,$connection,$attach) {
        project($db,$projectId);
        query($db,$attach ? 'INSERT INTO project_connections VALUES(?,?) ON CONFLICT DO NOTHING' : 'DELETE FROM project_connections WHERE project_id=? AND connection_key=?',[$projectId,$connection]);
        return $projectId;
    });
}
