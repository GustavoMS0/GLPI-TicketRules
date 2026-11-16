<?php

/**
 * ------------------------------------------------------------------------
 * Ticket Rules - Camada de compatibilidade GLPI 10 / 11 / 12
 *
 * Copyright (C) 2026 by G. Martins
 * ------------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of Ticket Rules.
 *
 * Ticket Rules is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Ticket Rules is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 * ------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
    die('Acesso direto não permitido.');
}

/**
 * Utilitários de compatibilidade entre GLPI 10, 11 e 12 e PHP 7.4+.
 */
class PluginTicketrulesCompat
{
    /**
     * Retorna a versão principal (major) do GLPI em execução.
     */
    public static function glpiMajor(): int
    {
        if (!defined('GLPI_VERSION')) {
            return 10;
        }
        $parts = explode('.', (string) GLPI_VERSION);
        $major = (int) ($parts[0] ?? 10);
        return $major > 0 ? $major : 10;
    }

    /**
     * Indica se CommonGLPI::$rightname possui declaração de tipo (GLPI 11+).
     */
    public static function typedRightname(): bool
    {
        if (!class_exists('CommonGLPI')) {
            return false;
        }
        try {
            $ref = new ReflectionProperty('CommonGLPI', 'rightname');
            return $ref->hasType();
        } catch (ReflectionException $e) {
            return false;
        }
    }

    /**
     * Retorna a URL base do plugin (relativa ao root_doc ou absoluta com url_base).
     * Evita Plugin::getWebDir() no GLPI 11+ (descontinuado no 11 e removido no 12).
     */
    public static function webPath(bool $absolute = false): string
    {
        global $CFG_GLPI;

        $beforeGlpi11 = !defined('GLPI_VERSION') || version_compare((string) GLPI_VERSION, '11.0.0-dev', '<');
        if ($beforeGlpi11 && class_exists('Plugin') && method_exists('Plugin', 'getWebDir')) {
            $dir = (string) Plugin::getWebDir('ticketrules', $absolute);
            if ($dir !== '') {
                return rtrim($dir, '/');
            }
        }

        if ($absolute) {
            $base = rtrim((string) ($CFG_GLPI['url_base'] ?? ''), '/');
            if ($base !== '') {
                return $base . '/plugins/ticketrules';
            }
        }

        $root = rtrim((string) ($CFG_GLPI['root_doc'] ?? ''), '/');
        return $root . '/plugins/ticketrules';
    }

    /**
     * Resolve o caminho relativo de um ativo estático conforme a estrutura do GLPI.
     * GLPI < 11 serve pela raiz do plugin (prefixo 'public/'); GLPI >= 11 serve de public/.
     */
    public static function assetPath(string $relative): string
    {
        $relative = ltrim($relative, '/');
        $isModern = defined('GLPI_VERSION') && version_compare((string) GLPI_VERSION, '11.0.0-dev', '>=');
        $prefix   = $isModern ? '' : 'public/';
        return $prefix . $relative;
    }

    /**
     * Encerra ou lança exceção HTTP 403 de forma compatível com GLPI 10, 11 e 12.
     */
    public static function denyAccess(): void
    {
        if (class_exists('Html') && method_exists('Html', 'displayRightError')) {
            Html::displayRightError();
            return;
        }

        if (class_exists('\\Glpi\\Exception\\Http\\AccessDeniedHttpException')) {
            throw new \Glpi\Exception\Http\AccessDeniedHttpException('Access denied');
        }

        if (!headers_sent()) {
            http_response_code(403);
        }
        exit;
    }

    /**
     * Decodifica entidades HTML armazenadas no banco pelo GLPI < 11 sem remover barras invertidas.
     */
    public static function fromCore(string $value): string
    {
        $beforeGlpi11 = !defined('GLPI_VERSION') || version_compare((string) GLPI_VERSION, '11.0.0-dev', '<');
        if ($beforeGlpi11) {
            if (
                class_exists('\\Glpi\\Toolbox\\Sanitizer')
                && method_exists('\\Glpi\\Toolbox\\Sanitizer', 'decodeHtmlSpecialChars')
            ) {
                return (string) \Glpi\Toolbox\Sanitizer::decodeHtmlSpecialChars($value);
            }
            return str_replace(
                ['&#60;', '&#62;', '&#38;', '&#39;', '&#34;'],
                ['<', '>', '&', "'", '"'],
                $value
            );
        }
        return $value;
    }

    /**
     * Reverte a sanitização global aplicada pelo GLPI < 11 em entradas de requisição ($_POST / $input).
     */
    public static function fromSanitizedInput(string $value): string
    {
        $beforeGlpi11 = !defined('GLPI_VERSION') || version_compare((string) GLPI_VERSION, '11.0.0-dev', '<');
        if ($beforeGlpi11) {
            if (
                class_exists('\\Glpi\\Toolbox\\Sanitizer')
                && method_exists('\\Glpi\\Toolbox\\Sanitizer', 'unsanitize')
            ) {
                return (string) \Glpi\Toolbox\Sanitizer::unsanitize($value);
            }
            return self::fromCore(stripslashes($value));
        }
        return $value;
    }

    /**
     * Escapa strings para gravação via $DB->insert/$DB->update no GLPI < 11.
     */
    public static function dbValues(array $values): array
    {
        global $DB;

        $beforeGlpi11 = !defined('GLPI_VERSION') || version_compare((string) GLPI_VERSION, '11.0.0-dev', '<');
        if ($beforeGlpi11 && isset($DB) && method_exists($DB, 'escape')) {
            foreach ($values as $k => $v) {
                if (is_string($v)) {
                    $values[$k] = $DB->escape($v);
                }
            }
        }
        return $values;
    }

    /**
     * Sanitiza dados antes de enviá-los a métodos de núcleo do GLPI < 11.
     *
     * @param mixed $values
     * @return mixed
     */
    public static function coreInput($values)
    {
        $beforeGlpi11 = !defined('GLPI_VERSION') || version_compare((string) GLPI_VERSION, '11.0.0-dev', '<');
        if ($beforeGlpi11) {
            if (class_exists('\\Glpi\\Toolbox\\Sanitizer') && method_exists('\\Glpi\\Toolbox\\Sanitizer', 'sanitize')) {
                return \Glpi\Toolbox\Sanitizer::sanitize($values);
            }
            if (class_exists('Toolbox') && method_exists('Toolbox', 'addslashes_deep')) {
                return Toolbox::addslashes_deep($values);
            }
        }
        return $values;
    }

    /**
     * Desfaz a sanitização global aplicada pelo GLPI < 11 em $_POST.
     */
    public static function normalizeInput(): void
    {
        $beforeGlpi11 = !defined('GLPI_VERSION') || version_compare((string) GLPI_VERSION, '11.0.0-dev', '<');
        if (
            $beforeGlpi11
            && isset($_POST)
            && is_array($_POST)
            && class_exists('\\Glpi\\Toolbox\\Sanitizer')
            && method_exists('\\Glpi\\Toolbox\\Sanitizer', 'unsanitize')
        ) {
            $_POST = \Glpi\Toolbox\Sanitizer::unsanitize($_POST);
        }
    }

    /**
     * Retorna os IDs das entidades ancestrais ordenados do pai mais próximo até 0.
     *
     * @return int[]
     */
    public static function getEntitiesAncestorsFallback(int $entities_id): array
    {
        global $DB;

        if ($entities_id <= 0) {
            return [];
        }

        $ancestors = [];
        if (isset($DB) && method_exists($DB, 'request')) {
            $visited = [$entities_id => true];
            $current = $entities_id;
            $depth   = 0;

            while ($current > 0 && $depth < 64) {
                $depth++;
                $parent = null;
                $it = $DB->request([
                    'SELECT' => ['entities_id'],
                    'FROM'   => 'glpi_entities',
                    'WHERE'  => ['id' => $current],
                    'LIMIT'  => 1,
                ]);
                foreach ($it as $row) {
                    $parent = (int) ($row['entities_id'] ?? -1);
                    break;
                }
                if ($parent === null || $parent < 0 || isset($visited[$parent])) {
                    break;
                }
                $visited[$parent] = true;
                $ancestors[]      = $parent;
                $current          = $parent;
            }

            if (!empty($ancestors)) {
                return $ancestors;
            }
        }

        if (function_exists('getAncestorsOf')) {
            $raw = getAncestorsOf('glpi_entities', $entities_id);
            if (is_array($raw)) {
                foreach ($raw as $id) {
                    $id = (int) $id;
                    if ($id >= 0 && $id !== $entities_id && !in_array($id, $ancestors, true)) {
                        $ancestors[] = $id;
                    }
                }
                if (count($ancestors) > 1 && $ancestors[0] === 0) {
                    $ancestors = array_reverse($ancestors);
                }
            }
        }

        if (!in_array(0, $ancestors, true)) {
            $ancestors[] = 0;
        }

        return $ancestors;
    }

    /**
     * Valida método POST e cabeçalhos de mesma origem quando presentes (Sec-Fetch-Site, Origin ou Referer).
     */
    public static function requireSafePost(): bool
    {
        global $CFG_GLPI;

        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? ''));
        if ($method !== 'POST') {
            return false;
        }

        $secFetchSite = strtolower(trim((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')));
        if ($secFetchSite !== '' && $secFetchSite !== 'same-origin') {
            return false;
        }

        $origin  = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
        $referer = trim((string) ($_SERVER['HTTP_REFERER'] ?? ''));

        if ($origin !== '') {
            return self::matchesServerOrigin($origin, $CFG_GLPI ?? []);
        }

        if ($referer !== '') {
            return self::matchesServerOrigin($referer, $CFG_GLPI ?? []);
        }

        // Quando proxy restrito remove Sec-Fetch-Site, Origin e Referer, mantém o POST
        // protegido pelo token CSRF validado previamente pelo núcleo do GLPI.
        return true;
    }

    /**
     * Compara esquema, host e porta de uma URL de origem contra HTTP_HOST e $CFG_GLPI['url_base'].
     */
    private static function matchesServerOrigin(string $url, array $cfgGlpi): bool
    {
        if (strtolower($url) === 'null') {
            return false;
        }

        $originScheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $originHost   = strtolower((string) parse_url($url, PHP_URL_HOST));
        $originPort   = parse_url($url, PHP_URL_PORT);

        if ($originHost === '' || ($originScheme !== 'http' && $originScheme !== 'https')) {
            return false;
        }

        $defaultOriginPort   = $originScheme === 'https' ? 443 : 80;
        $effectiveOriginPort = $originPort !== null ? (int) $originPort : $defaultOriginPort;

        $rawHttpHost = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
        if ($rawHttpHost !== '') {
            $parsed = self::splitHostPort($rawHttpHost);
            if ($parsed['host'] !== '' && $originHost === $parsed['host']) {
                if ($parsed['port'] !== null) {
                    if ($effectiveOriginPort === (int) $parsed['port']) {
                        return true;
                    }
                } elseif ($originPort === null || (int) $originPort === $defaultOriginPort) {
                    return true;
                }
            }
        }

        $urlBase = trim((string) ($cfgGlpi['url_base'] ?? ''));
        if ($urlBase !== '') {
            $baseScheme = strtolower((string) parse_url($urlBase, PHP_URL_SCHEME));
            $baseHost   = strtolower((string) parse_url($urlBase, PHP_URL_HOST));
            $basePort   = parse_url($urlBase, PHP_URL_PORT);
            if ($baseHost !== '' && $originHost === $baseHost) {
                $defaultBasePort   = $baseScheme === 'https' ? 443 : ($baseScheme === 'http' ? 80 : null);
                $effectiveBasePort = $basePort !== null ? (int) $basePort : $defaultBasePort;
                if ($effectiveBasePort === null || $effectiveOriginPort === $effectiveBasePort) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Separa host e porta de um cabeçalho Host (incluindo IPv6 entre colchetes).
     *
     * @return array{host: string, port: int|null}
     */
    private static function splitHostPort(string $value): array
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return ['host' => '', 'port' => null];
        }

        if (substr($value, 0, 1) === '[') {
            $end = strpos($value, ']');
            if ($end === false) {
                return ['host' => '', 'port' => null];
            }
            $host = substr($value, 0, $end + 1);
            $rest = substr($value, $end + 1);
            $port = (substr($rest, 0, 1) === ':') ? (int) substr($rest, 1) : null;
            return ['host' => $host, 'port' => ($port !== null && $port > 0) ? $port : null];
        }

        $parts = explode(':', $value);
        $host  = $parts[0] ?? '';
        $port  = isset($parts[1]) && ctype_digit($parts[1]) ? (int) $parts[1] : null;
        return ['host' => $host, 'port' => $port];
    }
}
