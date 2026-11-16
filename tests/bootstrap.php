<?php

/**
 * ------------------------------------------------------------------------
 * Ticket Rules - Bootstrap de testes unitários standalone (sem Composer)
 *
 * Copyright (C) 2026 by G. Martins (GPLv3+)
 * ------------------------------------------------------------------------
 */

namespace Glpi\Toolbox {
    if (!class_exists(Sanitizer::class)) {
        class Sanitizer
        {
            /**
             * @param mixed $value
             * @return mixed
             */
            public static function sanitize($value)
            {
                if (is_array($value)) {
                    foreach ($value as $k => $v) {
                        $value[$k] = self::sanitize($v);
                    }
                    return $value;
                }
                if (!is_string($value)) {
                    return $value;
                }
                $encoded = str_replace(['&', '<', '>', "'", '"'], ['&#38;', '&#60;', '&#62;', '&#39;', '&#34;'], $value);
                return addslashes($encoded);
            }

            /**
             * @param mixed $value
             * @return mixed
             */
            public static function decodeHtmlSpecialChars($value)
            {
                if (is_array($value)) {
                    foreach ($value as $k => $v) {
                        $value[$k] = self::decodeHtmlSpecialChars($v);
                    }
                    return $value;
                }
                if (!is_string($value)) {
                    return $value;
                }
                return str_replace(['&#60;', '&#62;', '&#38;', '&#39;', '&#34;'], ['<', '>', '&', "'", '"'], $value);
            }

            /**
             * @param mixed $value
             * @return mixed
             */
            public static function unsanitize($value)
            {
                if (is_array($value)) {
                    foreach ($value as $k => $v) {
                        $value[$k] = self::unsanitize($v);
                    }
                    return $value;
                }
                if (!is_string($value)) {
                    return $value;
                }
                return self::decodeHtmlSpecialChars(stripslashes($value));
            }
        }
    }
}

namespace Glpi\Exception\Http {
    $envVer = getenv('TICKETRULES_TEST_GLPI_VERSION');
    $ver    = ($envVer !== false && $envVer !== '') ? $envVer : (defined('GLPI_VERSION') ? GLPI_VERSION : '11.0.0');
    if (version_compare($ver, '12.0.0-dev', '>=') && !class_exists(AccessDeniedHttpException::class)) {
        class AccessDeniedHttpException extends \RuntimeException
        {
        }
    }
}

namespace {
    if (!defined('GLPI_ROOT')) {
        define('GLPI_ROOT', dirname(__DIR__));
    }

    $envVersion = getenv('TICKETRULES_TEST_GLPI_VERSION');
    if (!defined('GLPI_VERSION')) {
        define('GLPI_VERSION', ($envVersion !== false && $envVersion !== '') ? $envVersion : '11.0.0');
    }

    if (!defined('INFO')) {
        define('INFO', 0);
    }
    if (!defined('WARNING')) {
        define('WARNING', 2);
    }
    if (!defined('ERROR')) {
        define('ERROR', 3);
    }
    if (!defined('READ')) {
        define('READ', 1);
    }
    if (!defined('UPDATE')) {
        define('UPDATE', 2);
    }

    $GLOBALS['TEST_CLI_MODE'] = false;
    $GLOBALS['PLUGIN_HOOKS']  = [];
    $GLOBALS['CFG_GLPI']      = [
        'root_doc' => '',
        'url_base' => 'https://glpi.local',
    ];

    if (!function_exists('__')) {
        function __($str, $domain = 'glpi')
        {
            return (string) $str;
        }
    }

    if (!function_exists('isCommandLineInterface')) {
        function isCommandLineInterface()
        {
            return !empty($GLOBALS['TEST_CLI_MODE']);
        }
    }

    if (!function_exists('getAncestorsOf')) {
        function getAncestorsOf($table, $items_id)
        {
            global $DB;

            $items_id = (int) $items_id;
            if ($items_id <= 0 || !isset($DB)) {
                return [];
            }

            $chain   = [];
            $visited = [$items_id => true];
            $current = $items_id;
            $depth   = 0;

            while ($current > 0 && $depth < 64) {
                $depth++;
                $parent = null;
                foreach ($DB->request(['SELECT' => ['entities_id'], 'FROM' => $table, 'WHERE' => ['id' => $current], 'LIMIT' => 1]) as $row) {
                    $parent = (int) ($row['entities_id'] ?? -1);
                    break;
                }
                if ($parent === null || $parent < 0 || isset($visited[$parent])) {
                    break;
                }
                $visited[$parent] = true;
                $chain[]          = $parent;
                $current          = $parent;
            }

            // Simula retorno do núcleo do GLPI (da raiz 0 até o pai imediato)
            return array_reverse($chain);
        }
    }

    $typedRightname = getenv('TICKETRULES_TEST_TYPED_RIGHTNAME') === '1';
    if ($typedRightname) {
        class CommonGLPI
        {
            public static string $rightname = '';

            public static function getType()
            {
                return static::class;
            }
        }
    } else {
        class CommonGLPI
        {
            public static $rightname = '';

            public static function getType()
            {
                return static::class;
            }
        }
    }

    class DBmysql
    {
        /** @var array<string, array<int, array<string, mixed>>> */
        private $tables = [];

        /** @var array<string, int> */
        private $autoIncrement = [];

        /** @var array<string, array<int, string>> */
        private $coreSchemas = [
            'glpi_entities'       => ['id', 'entities_id', 'name'],
            'glpi_tickets'        => ['id', 'entities_id', 'name', 'content', 'status', 'type', 'itilcategories_id', 'locations_id', 'requesttypes_id', 'actiontime'],
            'glpi_tickets_users'  => ['id', 'tickets_id', 'users_id', 'type'],
            'glpi_groups_tickets' => ['id', 'tickets_id', 'groups_id', 'type'],
            'glpi_tickettasks'    => ['id', 'tickets_id', 'content', 'actiontime'],
            'glpi_itilsolutions'  => ['id', 'itemtype', 'items_id', 'content', 'status', 'users_id_approval'],
            'glpi_profiles'       => ['id', 'name', 'interface'],
        ];

        /** @var array<string, array<int, string>> */
        private $schemas = [];

        public function reset(): void
        {
            $this->tables        = [];
            $this->autoIncrement = [];
            $this->schemas       = [];
        }

        public function seed(string $table, array $rows): void
        {
            if (!isset($this->tables[$table])) {
                $this->tables[$table] = [];
            }
            $maxId = $this->autoIncrement[$table] ?? 0;
            foreach ($rows as $row) {
                if (!isset($row['id'])) {
                    $maxId++;
                    $row['id'] = $maxId;
                } else {
                    $row['id'] = (int) $row['id'];
                    if ($row['id'] > $maxId) {
                        $maxId = $row['id'];
                    }
                }
                $this->tables[$table][] = $row;
            }
            $this->autoIncrement[$table] = $maxId;
        }

        public function tableExists(string $table): bool
        {
            return isset($this->tables[$table]) || isset($this->schemas[$table]) || isset($this->coreSchemas[$table]);
        }

        public function fieldExists(string $table, string $field): bool
        {
            if (isset($this->schemas[$table])) {
                return in_array($field, $this->schemas[$table], true);
            }
            if (isset($this->coreSchemas[$table])) {
                return in_array($field, $this->coreSchemas[$table], true);
            }
            if (!empty($this->tables[$table])) {
                return array_key_exists($field, $this->tables[$table][0]);
            }
            return false;
        }

        public function doQuery(string $sql): bool
        {
            if (preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([a-zA-Z0-9_]+)`?/i', $sql, $m)) {
                $table = $m[1];
                if (!isset($this->tables[$table])) {
                    $this->tables[$table] = [];
                }
                if (preg_match_all('/^\s*`([a-zA-Z0-9_]+)`/m', $sql, $cols)) {
                    $this->schemas[$table] = $cols[1];
                }
                return true;
            }
            if (preg_match('/DROP\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([a-zA-Z0-9_]+)`?/i', $sql, $m)) {
                $table = $m[1];
                unset($this->tables[$table], $this->autoIncrement[$table], $this->schemas[$table]);
                return true;
            }
            return true;
        }

        public function escape(string $value): string
        {
            return addslashes($value);
        }

        public function request(array $criteria)
        {
            $from = (string) ($criteria['FROM'] ?? '');
            $rows = $this->tables[$from] ?? [];

            $where   = (array) ($criteria['WHERE'] ?? []);
            $matched = [];
            foreach ($rows as $row) {
                if ($this->matchesWhere($row, $where)) {
                    $matched[] = $row;
                }
            }

            if (!empty($criteria['ORDER'])) {
                $orders = is_array($criteria['ORDER']) ? $criteria['ORDER'] : [$criteria['ORDER']];
                $first  = trim((string) ($orders[0] ?? ''));
                if ($first !== '') {
                    $parts = preg_split('/\s+/', $first);
                    $col   = $parts[0] ?? 'id';
                    $dir   = strtoupper((string) ($parts[1] ?? 'ASC'));
                    usort($matched, static function (array $a, array $b) use ($col, $dir): int {
                        $va = $a[$col] ?? null;
                        $vb = $b[$col] ?? null;
                        if ($va == $vb) {
                            return 0;
                        }
                        $cmp = ($va < $vb) ? -1 : 1;
                        return $dir === 'DESC' ? -$cmp : $cmp;
                    });
                }
            }

            if (isset($criteria['LIMIT'])) {
                $limit   = max(0, (int) $criteria['LIMIT']);
                $matched = array_slice($matched, 0, $limit);
            }

            return new ArrayIterator($matched);
        }

        public function insert(string $table, array $params): bool
        {
            if (!isset($this->tables[$table])) {
                $this->tables[$table] = [];
            }
            $params = $this->normalizeDbWriteValues($params);
            if (!isset($params['id'])) {
                $next                        = ($this->autoIncrement[$table] ?? 0) + 1;
                $this->autoIncrement[$table] = $next;
                $params['id']                = $next;
            }
            $this->tables[$table][] = $params;
            return true;
        }

        public function update(string $table, array $params, array $where): bool
        {
            if (!isset($this->tables[$table])) {
                return false;
            }
            $params = $this->normalizeDbWriteValues($params);
            foreach ($this->tables[$table] as $idx => $row) {
                if ($this->matchesWhere($row, $where)) {
                    foreach ($params as $k => $v) {
                        $this->tables[$table][$idx][$k] = $v;
                    }
                }
            }
            return true;
        }

        public function delete(string $table, array $where): bool
        {
            if (!isset($this->tables[$table])) {
                return true;
            }
            $kept = [];
            foreach ($this->tables[$table] as $row) {
                if (!$this->matchesWhere($row, $where)) {
                    $kept[] = $row;
                }
            }
            $this->tables[$table] = $kept;
            return true;
        }

        private function normalizeDbWriteValues(array $params): array
        {
            if (version_compare((string) GLPI_VERSION, '11.0.0-dev', '<')) {
                foreach ($params as $k => $v) {
                    if (is_string($v)) {
                        $params[$k] = stripslashes($v);
                    }
                }
            }
            return $params;
        }

        private function matchesWhere(array $row, array $where): bool
        {
            foreach ($where as $k => $expected) {
                if ($k === 'NOT' && is_array($expected)) {
                    if ($this->matchesWhere($row, $expected)) {
                        return false;
                    }
                    continue;
                }
                if (!array_key_exists($k, $row)) {
                    return false;
                }
                $actual = $row[$k];
                if (is_array($expected)) {
                    if (!in_array($actual, $expected)) {
                        return false;
                    }
                } elseif ((string) $actual !== (string) $expected) {
                    return false;
                }
            }
            return true;
        }
    }

    class CommonDBTM extends CommonGLPI
    {
        /** @var array<string, mixed> */
        public $fields = [];

        /** @var array<string, mixed>|false */
        public $input = [];

        public static function getTable($classname = null)
        {
            return 'glpi_' . strtolower((string) ($classname ?? static::class)) . 's';
        }

        public function getID()
        {
            return (int) ($this->fields['id'] ?? 0);
        }

        public function getFromDB($id): bool
        {
            global $DB;

            $id = (int) $id;
            if ($id <= 0 || !isset($DB)) {
                return false;
            }
            $table = static::getTable();
            foreach ($DB->request(['FROM' => $table, 'WHERE' => ['id' => $id], 'LIMIT' => 1]) as $row) {
                $this->fields = $row;
                return true;
            }
            return false;
        }
    }

    class Ticket extends CommonDBTM
    {
        public const INCIDENT_TYPE = 1;
        public const DEMAND_TYPE   = 2;
        public const INCOMING      = 1;
        public const ASSIGNED      = 2;
        public const PLANNED       = 3;
        public const WAITING       = 4;
        public const SOLVED        = 5;
        public const CLOSED        = 6;

        public static function getTable($classname = null)
        {
            return 'glpi_tickets';
        }
    }

    class ITILSolution extends CommonDBTM
    {
        public static function getTable($classname = null)
        {
            return 'glpi_itilsolutions';
        }
    }

    class Session
    {
        /** @var int */
        public static $loginUserId = 2;

        /** @var bool */
        public static $cronMode = false;

        /** @var array<int, array{msg: string, check_once: bool, type: int}> */
        public static $messages = [];

        /** @var array<string, bool> */
        public static $rights = ['config' => true];

        /** @var array<int, bool>|null */
        public static $accessibleEntities = null;

        public static function reset(): void
        {
            self::$loginUserId        = 2;
            self::$cronMode           = false;
            self::$messages           = [];
            self::$rights             = ['config' => true];
            self::$accessibleEntities = null;
            $_SESSION['glpiactiveprofile'] = ['id' => 3, 'interface' => 'central'];
            $_SESSION['glpiactive_entity'] = 0;
            unset($_SESSION['glpicronuserrunning']);
        }

        public static function isCron(): bool
        {
            return self::$cronMode;
        }

        public static function getLoginUserID($force_human = true)
        {
            return self::$loginUserId > 0 ? self::$loginUserId : false;
        }

        public static function addMessageAfterRedirect($msg, $check_once = false, $message_type = INFO, $reset = false): void
        {
            if ($reset) {
                self::$messages = [];
            }
            self::$messages[] = [
                'msg'        => (string) $msg,
                'check_once' => (bool) $check_once,
                'type'       => (int) $message_type,
            ];
        }

        public static function haveRight($module, $right): bool
        {
            return !empty(self::$rights[$module]);
        }

        public static function checkRight($module, $right): void
        {
            if (!self::haveRight($module, $right)) {
                throw new RuntimeException('Right denied: ' . $module);
            }
        }

        public static function haveAccessToEntity($entities_id, $is_recursive = 0): bool
        {
            if (self::$accessibleEntities === null) {
                return true;
            }
            return !empty(self::$accessibleEntities[(int) $entities_id]);
        }
    }

    class Plugin
    {
        /** @var array<int, string> */
        public static $registered = [];

        /** @var bool */
        public static $webDirCalled = false;

        public static function registerClass($class, $params = []): void
        {
            self::$registered[] = (string) $class;
        }

        public static function getWebDir($plugin, $full = false): string
        {
            global $CFG_GLPI;
            self::$webDirCalled = true;
            $base = $full
                ? rtrim((string) ($CFG_GLPI['url_base'] ?? ''), '/')
                : rtrim((string) ($CFG_GLPI['root_doc'] ?? ''), '/');
            return $base . '/plugins/' . $plugin;
        }
    }

    if (version_compare((string) GLPI_VERSION, '12.0.0-dev', '<')) {
        class Html
        {
            /** @var bool */
            public static $rightErrorDisplayed = false;

            public static function closeForm($display = true): string
            {
                $out = '<input type="hidden" name="_glpi_csrf_token" value="test"></form>';
                if ($display) {
                    echo $out;
                }
                return $out;
            }

            public static function header($title = '', $url = '', $sector = '', $item = ''): void
            {
            }

            public static function footer(): void
            {
            }

            public static function redirect($location): void
            {
            }

            public static function displayRightError(): void
            {
                self::$rightErrorDisplayed = true;
            }
        }
    } else {
        class Html
        {
            public static function closeForm($display = true): string
            {
                $out = '<input type="hidden" name="_glpi_csrf_token" value="test"></form>';
                if ($display) {
                    echo $out;
                }
                return $out;
            }

            public static function header($title = '', $url = '', $sector = '', $item = ''): void
            {
            }

            public static function footer(): void
            {
            }

            public static function redirect($location): void
            {
            }
        }
    }

    class Dropdown
    {
        public static function getDropdownName($table, $id): string
        {
            return '#' . (int) $id;
        }
    }

    class Entity extends CommonDBTM
    {
        public static function dropdown($options = []): void
        {
            echo '<select name="entities_id"></select>';
        }
    }

    class Toolbox
    {
        /**
         * @param mixed $value
         * @return mixed
         */
        public static function addslashes_deep($value)
        {
            if (is_array($value)) {
                foreach ($value as $k => $v) {
                    $value[$k] = self::addslashes_deep($v);
                }
                return $value;
            }
            return is_string($value) ? addslashes($value) : $value;
        }

        public static function logInFile($file, $data): void
        {
        }
    }

    $GLOBALS['DB'] = new DBmysql();
    Session::reset();

    require_once GLPI_ROOT . '/ticketrules/setup.php';
    require_once GLPI_ROOT . '/ticketrules/hook.php';
    plugin_init_ticketrules();
}
