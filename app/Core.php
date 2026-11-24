<?php
declare(strict_types=1);

namespace Workspace;

use PDO;
use RuntimeException;
use Throwable;

const ROOT = __DIR__ . '/..';
const VERSION = '0.1.0';
const ROLES = ['owner'=>'Владелец', 'manager'=>'Менеджер команды', 'developer'=>'Разработчик', 'accountant'=>'Бухгалтер', 'legal'=>'Юрист', 'viewer'=>'Наблюдатель'];
const PROJECT_KINDS = ['internal'=>'Внутренний', 'client'=>'Клиентский', 'oss'=>'Open source'];
const STATES = ['todo'=>'К работе', 'doing'=>'В работе', 'done'=>'Готово'];
const DOC_KINDS = ['contract'=>'Договор', 'nda'=>'NDA', 'acceptance'=>'Акт', 'amendment'=>'Доп. соглашение', 'other'=>'Другой документ'];
const CURRENCIES = ['RUB','EUR','USD','PLN','GBP'];
const DIRECTIONS = ['income'=>'Поступление', 'expense'=>'Расход'];

final class Problem extends RuntimeException {
    public function __construct(string $message, public readonly int $status = 422) { parent::__construct($message); }
}

function can(array $user, string $permission): bool {
    $allowed = match ($permission) {
        'manage' => ['owner', 'manager'],
        'work' => ['owner', 'manager', 'developer'],
        'finance' => ['owner', 'accountant'],
        'legal' => ['owner', 'legal'],
        'connections' => ['owner', 'manager', 'developer'],
        'people' => ['owner', 'manager'],
        default => [],
    };
    return in_array($user['role'], $allowed, true);
}

function permit(array $user, string $permission): void {
    if (!can($user, $permission)) throw new Problem('Для этого действия нужна другая роль.', 403);
}

function dataDir(): string {
    $dir = getenv('WORKSPACE_DATA') ?: ROOT . '/var';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('Не удалось открыть каталог данных.');
    $dir = realpath($dir);
    $public = realpath(ROOT . '/public');
    if ($dir === false || $dir === $public || str_starts_with($dir, $public . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Каталог данных должен находиться вне public.');
    }
    return $dir;
}

function database(bool $install = false): PDO {
    if (PHP_INT_SIZE < 8) throw new Problem('Для сумм в кассовой книге требуется 64-разрядный PHP.', 503);
    $file = dataDir() . '/workspace.sqlite';
    if (!$install && !is_file($file)) throw new Problem('Рабочее пространство ещё не установлено. Выполните bin/workspace init.', 503);
    $db = new PDO('sqlite:' . $file, null, null, [
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    ]);
    $db->exec('PRAGMA busy_timeout=5000');
    $db->exec('PRAGMA foreign_keys=ON');
    if ($install) {
        $db->exec('PRAGMA journal_mode=WAL');
        atomic($db, static function () use ($db) {
            $version = (int)$db->query('PRAGMA user_version')->fetchColumn();
            if ($version === 0) {
                $db->exec(file_get_contents(ROOT . '/database/001.sql'));
                $db->exec('PRAGMA user_version=1');
            }
        });
    }
    if ((int)$db->query('PRAGMA user_version')->fetchColumn() !== 1) throw new Problem('Версия схемы данных несовместима с этим выпуском.', 503);
    return $db;
}

function atomic(PDO $db, callable $work): mixed {
    $db->exec('BEGIN IMMEDIATE');
    try {
        $result = $work();
        $db->exec('COMMIT');
        return $result;
    } catch (Throwable $e) {
        $db->exec('ROLLBACK');
        throw $e;
    }
}

function query(PDO $db, string $sql, array $params = []): \PDOStatement {
    $statement = $db->prepare($sql);
    $statement->execute($params);
    return $statement;
}

function entity(PDO $db, string $sql, array $params): array {
    $row = query($db, $sql, $params)->fetch();
    if (!$row) throw new Problem('Запись не найдена.', 404);
    return $row;
}

function word(mixed $s, int $limit, string $field, bool $required = true): string {
    if (!is_string($s)) throw new Problem('Некорректное поле «' . $field . '».');
    $s = trim($s);
    $length = preg_match_all('/./us', $s);
    if ($length === false || $length > $limit || ($required && $s === '') || preg_match('/[[:cntrl:]]/u', $s)) {
        throw new Problem('Проверьте поле «' . $field . '». Максимум символов — ' . $limit . '.');
    }
    return $s;
}

function choice(mixed $value, array $options): string {
    if (!is_string($value) || !array_key_exists($value, $options)) throw new Problem('Выберите значение из списка.');
    return $value;
}

function numberId(mixed $value): int {
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    if ($id === false) throw new Problem('Некорректный идентификатор.');
    return $id;
}

function calendarDate(mixed $value, bool $optional = false): ?string {
    if ($value === '' && $optional) return null;
    if (!is_string($value) || !preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $value, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
        throw new Problem('Укажите корректную календарную дату.');
    }
    return $value;
}

function action(PDO $db, array $user, string $key, string $name, array $data, callable $write): int {
    if (!preg_match('/\A[a-f0-9]{32}\z/', $key)) throw new Problem('Обновите форму и повторите операцию.');
    $digest = hash('sha256', json_encode([$name,$data], JSON_THROW_ON_ERROR));
    return atomic($db, static function () use ($db,$user,$key,$name,$digest,$write) {
        $old = query($db, 'SELECT fingerprint, object_id FROM action_log WHERE actor_id=? AND request_key=?', [$user['id'],$key])->fetch();
        if ($old) {
            if (!hash_equals($old['fingerprint'], $digest)) throw new Problem('Содержимое повторного запроса изменилось. Обновите форму.', 409);
            return (int)$old['object_id'];
        }
        $id = $write();
        query($db, 'INSERT INTO action_log(actor_id,request_key,fingerprint,action,object_id) VALUES(?,?,?,?,?)', [$user['id'],$key,$digest,$name,$id]);
        return $id;
    });
}

function moneyMinor(mixed $value): int {
    if (!is_string($value) || !preg_match('/\A(\d{1,9})(?:[.,](\d{1,2}))?\z/', trim($value), $m)) throw new Problem('Укажите положительную сумму, максимум два знака после запятой.');
    $amount = (int)$m[1] * 100 + (int)str_pad($m[2] ?? '', 2, '0');
    if ($amount < 1) throw new Problem('Сумма должна быть больше нуля.');
    return $amount;
}

function money(int $minor, bool $group = true): string {
    $absolute = abs($minor);
    $whole = (string)intdiv($absolute, 100);
    if ($group) $whole = preg_replace('/\B(?=(\d{3})+(?!\d))/', ' ', $whole);
    return ($minor < 0 ? '-' : '') . $whole . ',' . str_pad((string)($absolute % 100), 2, '0', STR_PAD_LEFT);
}

function normalizedEmail(mixed $raw): string {
    $email = strtolower(word($raw, 254, 'Email'));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Problem('Проверьте адрес электронной почты.');
    return $email;
}

function passwordHash(string $password): string {
    if (strlen($password) < 12 || strlen($password) > 72 || str_contains($password, "\0")) throw new Problem('Пароль должен занимать от 12 до 72 байт.');
    return password_hash($password, PASSWORD_DEFAULT);
}

function addUser(PDO $db, string $email, string $name, string $role, string $password, bool $first = false): void {
    $args = [normalizedEmail($email),word($name,90,'Имя'),choice($role,ROLES),passwordHash($password)];
    atomic($db, static function () use ($db,$args,$first) {
        if ($first && (int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn()) throw new Problem('Пространство уже установлено. Для нового пользователя используйте user:add.');
        if (query($db, 'SELECT 1 FROM users WHERE email=?', [$args[0]])->fetchColumn()) throw new Problem('Этот адрес уже зарегистрирован.');
        query($db, 'INSERT INTO users(email,name,role,password_hash) VALUES(?,?,?,?)', $args);
    });
}

function authenticate(PDO $db, string $email, string $password, string $peer): ?array {
    $email = strtolower(trim($email));
    $keys = [hash('sha256','email:'.$email)=>10, hash('sha256','ip:'.$peer)=>80];
    $now = time();
    atomic($db, static function () use ($db,$keys,$now) {
        query($db,'DELETE FROM login_attempts WHERE started<=?', [$now-600]);
        foreach ($keys as $key=>$limit) {
            $count = query($db,'SELECT attempts FROM login_attempts WHERE bucket=?',[$key])->fetchColumn();
            if ((int)$count >= $limit) throw new Problem('Слишком много попыток входа. Повторите через 10 минут.', 429);
            query($db,'INSERT INTO login_attempts VALUES(?,?,1) ON CONFLICT(bucket) DO UPDATE SET attempts=attempts+1',[$key,$now]);
        }
    });
    $user = query($db,'SELECT * FROM users WHERE email=? AND enabled=1',[$email])->fetch();
    // Valid fixed hash gives an unknown email the same password verification path.
    $dummy = '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
    if (strlen($password) > 72 || str_contains($password, "\0") || !password_verify($password, $user['password_hash'] ?? $dummy) || !$user) return null;
    atomic($db, static function () use ($db,$keys) {
        foreach ($keys as $key=>$limit) query($db,'UPDATE login_attempts SET attempts=MAX(0, attempts-1) WHERE bucket=?',[$key]);
    });
    return $user;
}
