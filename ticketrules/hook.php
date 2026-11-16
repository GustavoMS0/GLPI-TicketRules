<?php

/**
 * ------------------------------------------------------------------------
 * Ticket Rules - Instalação e desinstalação
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

/**
 * Cria a tabela de configuração por entidade na instalação ou atualização.
 */
function plugin_ticketrules_install()
{
    include_once __DIR__ . '/inc/compat.class.php';
    include_once __DIR__ . '/inc/config.class.php';

    PluginTicketrulesConfig::installSchema();
    return true;
}

/**
 * Remove a tabela de configuração do plugin na desinstalação para não deixar tabelas órfãs.
 */
function plugin_ticketrules_uninstall()
{
    include_once __DIR__ . '/inc/compat.class.php';
    include_once __DIR__ . '/inc/config.class.php';

    PluginTicketrulesConfig::uninstallSchema();
    return true;
}
