<?php

/**
 * ------------------------------------------------------------------------
 * Ticket Rules - Regras obrigatórias na solução e fechamento de chamados
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

define('PLUGIN_TICKETRULES_VERSION', '1.0.0');
define('PLUGIN_TICKETRULES_MIN_GLPI', '10.0.0');
define('PLUGIN_TICKETRULES_MAX_GLPI', '12.0.99');
define('PLUGIN_TICKETRULES_MIN_PHP', '7.4');

/**
 * Inicializa hooks, classes e recursos estáticos do plugin.
 */
function plugin_init_ticketrules()
{
    global $PLUGIN_HOOKS;

    include_once __DIR__ . '/inc/compat.class.php';
    include_once __DIR__ . '/inc/config.class.php';
    include_once __DIR__ . '/inc/validator.class.php';

    $PLUGIN_HOOKS['csrf_compliant']['ticketrules'] = true;
    $PLUGIN_HOOKS['config_page']['ticketrules']    = 'front/config.form.php';
    $PLUGIN_HOOKS['add_css']['ticketrules']        = [
        PluginTicketrulesCompat::assetPath('css/ticketrules.css'),
    ];

    if (class_exists('Plugin') && method_exists('Plugin', 'registerClass')) {
        Plugin::registerClass(PluginTicketrulesConfig::class);
    }

    $PLUGIN_HOOKS['pre_item_add']['ticketrules'] = [
        'ITILSolution' => [PluginTicketrulesValidator::class, 'preItemAddSolution'],
        'Ticket'       => [PluginTicketrulesValidator::class, 'preItemAddTicket'],
    ];

    $PLUGIN_HOOKS['pre_item_update']['ticketrules'] = [
        'ITILSolution' => [PluginTicketrulesValidator::class, 'preItemUpdateSolution'],
        'Ticket'       => [PluginTicketrulesValidator::class, 'preItemUpdateTicket'],
    ];
}

/**
 * Metadados exibidos em Configurar > Plugins.
 */
function plugin_version_ticketrules()
{
    return [
        'name'         => 'Ticket Rules',
        'version'      => PLUGIN_TICKETRULES_VERSION,
        'author'       => 'G. Martins',
        'license'      => 'GPLv3+',
        'homepage'     => 'https://github.com/GustavoMS0/GLPI-TicketRules',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_TICKETRULES_MIN_GLPI,
                'max' => PLUGIN_TICKETRULES_MAX_GLPI,
            ],
            'php'  => [
                'min' => PLUGIN_TICKETRULES_MIN_PHP,
            ],
        ],
    ];
}

/**
 * Verifica pré-requisitos de PHP e GLPI antes da instalação/ativação.
 */
function plugin_ticketrules_check_prerequisites()
{
    if (version_compare(PHP_VERSION, PLUGIN_TICKETRULES_MIN_PHP, '<')) {
        echo sprintf('Este plugin exige PHP %s ou superior.', PLUGIN_TICKETRULES_MIN_PHP);
        return false;
    }

    if (defined('GLPI_VERSION')) {
        if (version_compare((string) GLPI_VERSION, PLUGIN_TICKETRULES_MIN_GLPI, '<')) {
            echo sprintf('Este plugin exige GLPI %s ou superior.', PLUGIN_TICKETRULES_MIN_GLPI);
            return false;
        }
        if (version_compare((string) GLPI_VERSION, PLUGIN_TICKETRULES_MAX_GLPI, '>')) {
            echo sprintf('Este plugin suporta até GLPI %s.', PLUGIN_TICKETRULES_MAX_GLPI);
            return false;
        }
    }

    return true;
}

/**
 * Verifica se o plugin está pronto para ativação.
 */
function plugin_ticketrules_check_config($verbose = false)
{
    if ($verbose) {
        echo function_exists('__') ? __('Instalado / configurado', 'ticketrules') : 'Instalado / configurado';
    }
    return true;
}
