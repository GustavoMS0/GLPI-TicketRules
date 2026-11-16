<?php

/**
 * ------------------------------------------------------------------------
 * Ticket Rules - Configuração por entidade e herança recursiva
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

interface PluginTicketrulesConfigConstants
{
    public const TABLE = 'glpi_plugin_ticketrules_configs';
    public const TICKET_TYPE_INCIDENT = 1;
    public const TICKET_TYPE_REQUEST  = 2;
}

trait PluginTicketrulesConfigBody
{
    /**
     * Nome legível do item no GLPI.
     */
    public static function getTypeName($nb = 0)
    {
        return function_exists('__') ? __('Ticket Rules', 'ticketrules') : 'Ticket Rules';
    }

    /**
     * Tabela associada à configuração por entidade.
     */
    public static function getTable($classname = null)
    {
        return self::TABLE;
    }

    /**
     * Cria a tabela do plugin na instalação ou atualização.
     */
    public static function installSchema(): void
    {
        global $DB;

        if (!$DB->tableExists(self::TABLE)) {
            $sql = "CREATE TABLE `" . self::TABLE . "` (
                    `id` int unsigned NOT NULL AUTO_INCREMENT,
                    `entities_id` int unsigned NOT NULL DEFAULT '0',
                    `is_recursive` tinyint NOT NULL DEFAULT '1',
                    `is_active` tinyint NOT NULL DEFAULT '0',
                    `require_category` tinyint NOT NULL DEFAULT '0',
                    `require_type` tinyint NOT NULL DEFAULT '0',
                    `require_technician` tinyint NOT NULL DEFAULT '0',
                    `require_group` tinyint NOT NULL DEFAULT '0',
                    `require_location` tinyint NOT NULL DEFAULT '0',
                    `require_requesttype` tinyint NOT NULL DEFAULT '0',
                    `require_task_or_time` tinyint NOT NULL DEFAULT '0',
                    `min_solution_length` int unsigned NOT NULL DEFAULT '0',
                    `apply_on_solve` tinyint NOT NULL DEFAULT '1',
                    `apply_on_close` tinyint NOT NULL DEFAULT '1',
                    `apply_to_types` text DEFAULT NULL,
                    `exempt_profiles` text DEFAULT NULL,
                    `date_mod` timestamp NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uniq_entities_id` (`entities_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC";
            if (method_exists($DB, 'doQuery')) {
                $DB->doQuery($sql);
            } else {
                $DB->query($sql);
            }
        }
    }

    /**
     * Remove a tabela do plugin na desinstalação.
     */
    public static function uninstallSchema(): void
    {
        global $DB;

        if ($DB->tableExists(self::TABLE)) {
            $sql = 'DROP TABLE IF EXISTS `' . self::TABLE . '`';
            if (method_exists($DB, 'doQuery')) {
                $DB->doQuery($sql);
            } else {
                $DB->query($sql);
            }
        }
    }

    /**
     * Configuração padrão segura (inativa) quando não há regra definida na árvore de entidades.
     */
    public static function defaultConfig(int $entities_id = 0): array
    {
        return [
            'id'                   => 0,
            'entities_id'          => $entities_id,
            'is_recursive'         => 1,
            'is_active'            => 0,
            'require_category'     => 0,
            'require_type'         => 0,
            'require_technician'   => 0,
            'require_group'        => 0,
            'require_location'     => 0,
            'require_requesttype'  => 0,
            'require_task_or_time' => 0,
            'min_solution_length'  => 0,
            'apply_on_solve'       => 1,
            'apply_on_close'       => 1,
            'apply_to_types'       => [self::TICKET_TYPE_INCIDENT, self::TICKET_TYPE_REQUEST],
            'exempt_profiles'      => [],
            'date_mod'             => null,
            'inherited_from'       => null,
        ];
    }

    /**
     * Retorna a configuração própria da entidade, ou null se não houver registro direto.
     */
    public static function getOwnConfig(int $entities_id): ?array
    {
        global $DB;

        if (!isset($DB) || !method_exists($DB, 'request')) {
            return null;
        }
        if (method_exists($DB, 'tableExists') && !$DB->tableExists(self::TABLE)) {
            return null;
        }

        $it = $DB->request([
            'FROM'  => self::TABLE,
            'WHERE' => ['entities_id' => $entities_id],
            'LIMIT' => 1,
        ]);

        foreach ($it as $row) {
            return self::normalizeRow($row);
        }

        return null;
    }

    /**
     * Resolve a regra efetiva para uma entidade:
     * 1) registro exato da entidade (mesmo com is_recursive = 0);
     * 2) primeiro ancestral (do pai mais próximo até 0) com is_recursive = 1;
     * 3) preset padrão inativo caso nenhum exista.
     */
    public static function resolveForEntity(int $entities_id): array
    {
        $own = self::getOwnConfig($entities_id);
        if ($own !== null) {
            return $own;
        }

        $ancestors = PluginTicketrulesCompat::getEntitiesAncestorsFallback($entities_id);
        foreach ($ancestors as $ancestorId) {
            $row = self::getOwnConfig((int) $ancestorId);
            if ($row !== null && (int) ($row['is_recursive'] ?? 0) === 1) {
                $row['inherited_from'] = (int) $ancestorId;
                return $row;
            }
        }

        return self::defaultConfig($entities_id);
    }

    /**
     * Salva ou atualiza a configuração de uma entidade.
     */
    public static function saveConfig(int $entities_id, array $post): bool
    {
        global $DB;

        if ($entities_id < 0 || !isset($DB)) {
            return false;
        }

        if (method_exists($DB, 'tableExists') && !$DB->tableExists(self::TABLE)) {
            self::installSchema();
        }

        $types = [];
        if (array_key_exists('apply_to_types', $post)) {
            $rawTypes = is_array($post['apply_to_types']) ? $post['apply_to_types'] : [$post['apply_to_types']];
            foreach ($rawTypes as $t) {
                if (!is_scalar($t)) {
                    continue;
                }
                $val = (int) $t;
                if (in_array($val, [self::TICKET_TYPE_INCIDENT, self::TICKET_TYPE_REQUEST], true) && !in_array($val, $types, true)) {
                    $types[] = $val;
                }
            }
        } elseif (empty($post['apply_to_types_present'])) {
            $types = [self::TICKET_TYPE_INCIDENT, self::TICKET_TYPE_REQUEST];
        }

        $exemptProfiles = [];
        if (!empty($post['exempt_profiles']) && is_array($post['exempt_profiles'])) {
            foreach ($post['exempt_profiles'] as $pid) {
                if (!is_scalar($pid)) {
                    continue;
                }
                $pid = (int) $pid;
                if ($pid > 0 && !in_array($pid, $exemptProfiles, true)) {
                    $exemptProfiles[] = $pid;
                }
            }
        }

        $minLength = (int) ($post['min_solution_length'] ?? 0);
        if ($minLength < 0) {
            $minLength = 0;
        } elseif ($minLength > 10000) {
            $minLength = 10000;
        }

        $data = [
            'is_recursive'         => self::toBoolInt($post['is_recursive'] ?? 0),
            'is_active'            => self::toBoolInt($post['is_active'] ?? 0),
            'require_category'     => self::toBoolInt($post['require_category'] ?? 0),
            'require_type'         => self::toBoolInt($post['require_type'] ?? 0),
            'require_technician'   => self::toBoolInt($post['require_technician'] ?? 0),
            'require_group'        => self::toBoolInt($post['require_group'] ?? 0),
            'require_location'     => self::toBoolInt($post['require_location'] ?? 0),
            'require_requesttype'  => self::toBoolInt($post['require_requesttype'] ?? 0),
            'require_task_or_time' => self::toBoolInt($post['require_task_or_time'] ?? 0),
            'min_solution_length'  => $minLength,
            'apply_on_solve'       => self::toBoolInt($post['apply_on_solve'] ?? 0),
            'apply_on_close'       => self::toBoolInt($post['apply_on_close'] ?? 0),
            'apply_to_types'       => json_encode($types),
            'exempt_profiles'      => json_encode($exemptProfiles),
            'date_mod'             => date('Y-m-d H:i:s'),
        ];

        $values   = PluginTicketrulesCompat::dbValues($data);
        $existing = self::getOwnConfig($entities_id);

        if ($existing !== null) {
            return (bool) $DB->update(self::TABLE, $values, ['entities_id' => $entities_id]);
        }

        $values['entities_id'] = $entities_id;
        return (bool) $DB->insert(self::TABLE, $values);
    }

    /**
     * Remove a configuração específica de uma entidade para voltar a herdar da entidade pai.
     */
    public static function deleteConfig(int $entities_id): bool
    {
        global $DB;

        if (!isset($DB) || !method_exists($DB, 'delete')) {
            return false;
        }
        if (method_exists($DB, 'tableExists') && !$DB->tableExists(self::TABLE)) {
            return true;
        }

        return (bool) $DB->delete(self::TABLE, ['entities_id' => $entities_id]);
    }

    /**
     * Normaliza uma linha do banco de dados para tipos escalares e arrays previsíveis.
     */
    private static function normalizeRow(array $row): array
    {
        $rawTypes    = (string) ($row['apply_to_types'] ?? '');
        $rawProfiles = (string) ($row['exempt_profiles'] ?? '');

        $decodedTypes    = $rawTypes !== '' ? json_decode($rawTypes, true) : null;
        $decodedProfiles = $rawProfiles !== '' ? json_decode($rawProfiles, true) : null;

        $types = [];
        if (is_array($decodedTypes)) {
            foreach ($decodedTypes as $t) {
                $val = (int) $t;
                if (in_array($val, [self::TICKET_TYPE_INCIDENT, self::TICKET_TYPE_REQUEST], true) && !in_array($val, $types, true)) {
                    $types[] = $val;
                }
            }
        } else {
            $types = [self::TICKET_TYPE_INCIDENT, self::TICKET_TYPE_REQUEST];
        }

        $profiles = [];
        if (is_array($decodedProfiles)) {
            foreach ($decodedProfiles as $p) {
                $pid = (int) $p;
                if ($pid > 0 && !in_array($pid, $profiles, true)) {
                    $profiles[] = $pid;
                }
            }
        }

        return [
            'id'                   => (int) ($row['id'] ?? 0),
            'entities_id'          => (int) ($row['entities_id'] ?? 0),
            'is_recursive'         => (int) ($row['is_recursive'] ?? 0),
            'is_active'            => (int) ($row['is_active'] ?? 0),
            'require_category'     => (int) ($row['require_category'] ?? 0),
            'require_type'         => (int) ($row['require_type'] ?? 0),
            'require_technician'   => (int) ($row['require_technician'] ?? 0),
            'require_group'        => (int) ($row['require_group'] ?? 0),
            'require_location'     => (int) ($row['require_location'] ?? 0),
            'require_requesttype'  => (int) ($row['require_requesttype'] ?? 0),
            'require_task_or_time' => (int) ($row['require_task_or_time'] ?? 0),
            'min_solution_length'  => max(0, (int) ($row['min_solution_length'] ?? 0)),
            'apply_on_solve'       => (int) ($row['apply_on_solve'] ?? 0),
            'apply_on_close'       => (int) ($row['apply_on_close'] ?? 0),
            'apply_to_types'       => $types,
            'exempt_profiles'      => $profiles,
            'date_mod'             => $row['date_mod'] ?? null,
            'inherited_from'       => null,
        ];
    }

    /**
     * Converte entrada de formulário/checkbox em 0 ou 1.
     *
     * @param mixed $val
     */
    private static function toBoolInt($val): int
    {
        return !empty($val) ? 1 : 0;
    }
}

if (PluginTicketrulesCompat::typedRightname()) {
    class PluginTicketrulesConfig extends CommonDBTM implements PluginTicketrulesConfigConstants
    {
        use PluginTicketrulesConfigBody;

        public static string $rightname = 'config';
    }
} else {
    class PluginTicketrulesConfig extends CommonDBTM implements PluginTicketrulesConfigConstants
    {
        use PluginTicketrulesConfigBody;

        public static $rightname = 'config';
    }
}
