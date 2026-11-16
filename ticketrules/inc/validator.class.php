<?php

/**
 * ------------------------------------------------------------------------
 * Ticket Rules - Validação de regras na solução e encerramento de chamados
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
 * Valida regras configuradas por entidade nos hooks de ITILSolution e Ticket.
 */
class PluginTicketrulesValidator
{
    public const STATUS_SOLVED     = 5;
    public const STATUS_CLOSED     = 6;
    public const ACTOR_REQUESTER   = 1;
    public const ACTOR_ASSIGN      = 2;
    public const SOLUTION_ACCEPTED = 3;
    public const SOLUTION_REFUSED  = 4;

    /**
     * Calcula a quantidade de caracteres visíveis de um texto HTML, removendo tags,
     * decodificando entidades e colapsando espaços múltiplos.
     */
    public static function visibleLength(string $html, bool $isSanitizedInput = false): int
    {
        $text = $isSanitizedInput
            ? PluginTicketrulesCompat::fromSanitizedInput($html)
            : PluginTicketrulesCompat::fromCore($html);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $collapsed = preg_replace('/\s+/u', ' ', $text);
        if ($collapsed === null) {
            $normalized = str_replace("\xc2\xa0", ' ', $text);
            $collapsed  = preg_replace('/\s+/', ' ', $normalized);
            if ($collapsed === null) {
                $collapsed = $normalized;
            }
        }

        $trimmed = trim($collapsed);
        if ($trimmed === '') {
            return 0;
        }

        if (function_exists('mb_strlen')) {
            return (int) mb_strlen($trimmed, 'UTF-8');
        }

        return strlen($trimmed);
    }

    /**
     * Hook pre_item_add para ITILSolution.
     */
    public static function preItemAddSolution(CommonDBTM $item): void
    {
        if (!is_array($item->input) || empty($item->input)) {
            return;
        }

        self::validateSolutionHook($item, false);
    }

    /**
     * Hook pre_item_update para ITILSolution.
     * Ignora quando 'content' não foi enviado ou não mudou (ex.: aprovação/recusa pelo requerente).
     */
    public static function preItemUpdateSolution(CommonDBTM $item): void
    {
        if (!is_array($item->input) || empty($item->input)) {
            return;
        }

        if (!array_key_exists('content', $item->input)) {
            return;
        }

        $oldContent = PluginTicketrulesCompat::fromCore((string) ($item->fields['content'] ?? ''));
        $newContent = PluginTicketrulesCompat::fromSanitizedInput((string) $item->input['content']);
        if ($oldContent === $newContent) {
            return;
        }

        self::validateSolutionHook($item, true);
    }

    /**
     * Hook pre_item_add para Ticket.
     * Impede criação direta de chamado já em estado Solucionado (5) ou Fechado (6) sem os requisitos.
     */
    public static function preItemAddTicket(CommonDBTM $item): void
    {
        if (!is_array($item->input) || empty($item->input)) {
            return;
        }

        $newStatus = (int) ($item->input['status'] ?? 0);
        if ($newStatus !== self::STATUS_SOLVED && $newStatus !== self::STATUS_CLOSED) {
            return;
        }

        if (self::isNonInteractiveContext()) {
            return;
        }

        $entitiesId = (int) ($item->input['entities_id'] ?? ($_SESSION['glpiactive_entity'] ?? 0));
        $config     = PluginTicketrulesConfig::resolveForEntity($entitiesId);

        if (self::isConfigSkipped($config, (int) ($item->input['type'] ?? 0))) {
            return;
        }

        if ($newStatus === self::STATUS_SOLVED && (int) ($config['apply_on_solve'] ?? 0) !== 1) {
            return;
        }

        if ($newStatus === self::STATUS_CLOSED && (int) ($config['apply_on_close'] ?? 0) !== 1) {
            return;
        }

        $errors = self::collectValidationErrors($config, 0, $item->input, $item->input, '', false);
        if (!empty($errors)) {
            self::blockItem($item, $errors);
        }
    }

    /**
     * Hook pre_item_update para Ticket.
     */
    public static function preItemUpdateTicket(CommonDBTM $item): void
    {
        if (!is_array($item->input) || empty($item->input)) {
            return;
        }

        if (!array_key_exists('status', $item->input)) {
            return;
        }

        $oldStatus = (int) ($item->fields['status'] ?? 0);
        $newStatus = (int) $item->input['status'];

        if ($newStatus === $oldStatus) {
            return;
        }

        if ($newStatus !== self::STATUS_SOLVED && $newStatus !== self::STATUS_CLOSED) {
            return;
        }

        if (self::isNonInteractiveContext()) {
            return;
        }

        $ticketsId = (int) ($item->fields['id'] ?? $item->input['id'] ?? $item->getID());
        if ($ticketsId <= 0) {
            return;
        }

        $merged     = array_merge($item->fields, $item->input);
        $entitiesId = (int) ($merged['entities_id'] ?? 0);
        $config     = PluginTicketrulesConfig::resolveForEntity($entitiesId);

        if (self::isConfigSkipped($config, (int) ($merged['type'] ?? 0))) {
            return;
        }

        if ($newStatus === self::STATUS_SOLVED) {
            if ($oldStatus >= self::STATUS_SOLVED || (int) ($config['apply_on_solve'] ?? 0) !== 1) {
                return;
            }
        } else {
            // Transição para CLOSED (6)
            if ($oldStatus === self::STATUS_SOLVED) {
                if (self::getActiveInterface() !== 'central') {
                    return;
                }
                if (self::isRequesterApprovalClose($ticketsId, $item->input)) {
                    return;
                }
            }
            if ((int) ($config['apply_on_close'] ?? 0) !== 1) {
                return;
            }
        }

        $solutionHtml = self::fetchLatestSolutionContent($ticketsId);
        $errors       = self::collectValidationErrors($config, $ticketsId, $merged, $item->input, $solutionHtml, false);

        if (!empty($errors)) {
            self::blockItem($item, $errors);
        }
    }

    /**
     * Executa a validação para hooks de adição/atualização de ITILSolution.
     */
    private static function validateSolutionHook(CommonDBTM $item, bool $isUpdate): void
    {
        if (self::isNonInteractiveContext()) {
            return;
        }

        $itemtype = (string) ($item->input['itemtype'] ?? $item->fields['itemtype'] ?? '');
        if ($itemtype !== 'Ticket') {
            return;
        }

        $ticketsId = (int) ($item->input['items_id'] ?? $item->fields['items_id'] ?? 0);
        if ($ticketsId <= 0) {
            return;
        }

        $ticket = new Ticket();
        if (!$ticket->getFromDB($ticketsId)) {
            return;
        }

        $entitiesId = (int) ($ticket->fields['entities_id'] ?? 0);
        $config     = PluginTicketrulesConfig::resolveForEntity($entitiesId);

        if (self::isConfigSkipped($config, (int) ($ticket->fields['type'] ?? 0))) {
            return;
        }

        if ((int) ($config['apply_on_solve'] ?? 0) !== 1) {
            return;
        }

        $hasInputContent  = array_key_exists('content', $item->input);
        $solutionHtml     = $hasInputContent
            ? (string) $item->input['content']
            : ($isUpdate ? (string) ($item->fields['content'] ?? '') : '');
        $errors           = self::collectValidationErrors($config, $ticketsId, $ticket->fields, [], $solutionHtml, $hasInputContent);

        if (!empty($errors)) {
            self::blockItem($item, $errors);
        }
    }

    /**
     * Verifica se o contexto atual é CLI, cron ou sem usuário interativo autenticado.
     */
    private static function isNonInteractiveContext(): bool
    {
        if (function_exists('isCommandLineInterface') && isCommandLineInterface()) {
            return true;
        }

        if (class_exists('Session') && method_exists('Session', 'isCron') && Session::isCron()) {
            return true;
        }

        if (!empty($_SESSION['glpicronuserrunning'])) {
            return true;
        }

        if (!class_exists('Session') || !method_exists('Session', 'getLoginUserID')) {
            return true;
        }

        return (int) Session::getLoginUserID(true) <= 0;
    }

    /**
     * Verifica inatividade da regra, tipo de chamado e perfil isento.
     */
    private static function isConfigSkipped(array $config, int $ticketType): bool
    {
        if ((int) ($config['is_active'] ?? 0) !== 1) {
            return true;
        }

        $allowedTypes = array_map('intval', (array) ($config['apply_to_types'] ?? []));
        if ($ticketType > 0) {
            if (!in_array($ticketType, $allowedTypes, true)) {
                return true;
            }
        } elseif (empty($allowedTypes) && (int) ($config['require_type'] ?? 0) !== 1) {
            return true;
        }

        $activeProfileId = (int) ($_SESSION['glpiactiveprofile']['id'] ?? 0);
        $exemptProfiles  = array_map('intval', (array) ($config['exempt_profiles'] ?? []));
        if ($activeProfileId > 0 && in_array($activeProfileId, $exemptProfiles, true)) {
            return true;
        }

        return false;
    }

    /**
     * Retorna a interface ativa da sessão ('central', 'helpdesk', etc.).
     */
    private static function getActiveInterface(): string
    {
        $iface = strtolower(trim((string) ($_SESSION['glpiactiveprofile']['interface'] ?? 'central')));
        return $iface !== '' ? $iface : 'central';
    }

    /**
     * Identifica se a transição SOLVED -> CLOSED corresponde à aprovação de solução pelo requerente.
     */
    private static function isRequesterApprovalClose(int $ticketsId, array $ticketInput): bool
    {
        global $DB;

        $userId = class_exists('Session') && method_exists('Session', 'getLoginUserID')
            ? (int) Session::getLoginUserID(true)
            : 0;

        if ($userId <= 0 || !self::hasTicketUser($ticketsId, $userId, self::ACTOR_REQUESTER)) {
            return false;
        }

        $hasSolutionRows = false;
        if (
            isset($DB)
            && method_exists($DB, 'request')
            && (!method_exists($DB, 'tableExists') || $DB->tableExists('glpi_itilsolutions'))
        ) {
            $it = $DB->request([
                'SELECT' => ['status', 'users_id_approval'],
                'FROM'   => 'glpi_itilsolutions',
                'WHERE'  => [
                    'itemtype' => 'Ticket',
                    'items_id' => $ticketsId,
                ],
                'ORDER'  => ['id DESC'],
                'LIMIT'  => 1,
            ]);

            foreach ($it as $row) {
                $hasSolutionRows = true;
                return (int) ($row['status'] ?? 0) === self::SOLUTION_ACCEPTED
                    && (int) ($row['users_id_approval'] ?? 0) > 0;
            }
        }

        if ($hasSolutionRows) {
            return false;
        }

        if (!empty($ticketInput['_accepted']) || !empty($ticketInput['_solution_approval'])) {
            return true;
        }

        return isset($_POST['add_close']) || isset($_POST['approve']);
    }

    /**
     * Avalia todas as regras habilitadas e retorna a lista de pendências encontradas.
     *
     * @return string[]
     */
    private static function collectValidationErrors(
        array $config,
        int $ticketsId,
        array $ticketFields,
        array $ticketInput,
        string $solutionHtml,
        bool $solutionIsSanitizedInput
    ): array {
        $errors = [];

        if ((int) ($config['require_category'] ?? 0) === 1 && (int) ($ticketFields['itilcategories_id'] ?? 0) <= 0) {
            $errors[] = self::t('Categoria obrigatória não informada');
        }

        if ((int) ($config['require_type'] ?? 0) === 1 && (int) ($ticketFields['type'] ?? 0) <= 0) {
            $errors[] = self::t('Tipo do chamado obrigatório não informado');
        }

        if ((int) ($config['require_technician'] ?? 0) === 1 && !self::hasTechnician($ticketsId, $ticketFields, $ticketInput)) {
            $errors[] = self::t('Técnico atribuído obrigatório');
        }

        if ((int) ($config['require_group'] ?? 0) === 1 && !self::hasGroup($ticketsId, $ticketFields, $ticketInput)) {
            $errors[] = self::t('Grupo técnico atribuído obrigatório');
        }

        if ((int) ($config['require_location'] ?? 0) === 1 && (int) ($ticketFields['locations_id'] ?? 0) <= 0) {
            $errors[] = self::t('Localização obrigatória não informada');
        }

        if ((int) ($config['require_requesttype'] ?? 0) === 1 && (int) ($ticketFields['requesttypes_id'] ?? 0) <= 0) {
            $errors[] = self::t('Origem da requisição obrigatória não informada');
        }

        if ((int) ($config['require_task_or_time'] ?? 0) === 1) {
            $hasTime = (int) ($ticketFields['actiontime'] ?? 0) > 0;
            if (!$hasTime && !self::hasTicketTask($ticketsId)) {
                $errors[] = self::t('É necessário registrar ao menos uma tarefa ou tempo trabalhado');
            }
        }

        $minLength = (int) ($config['min_solution_length'] ?? 0);
        if ($minLength > 0) {
            $actualLength = self::visibleLength($solutionHtml, $solutionIsSanitizedInput);
            if ($actualLength < $minLength) {
                $errors[] = sprintf(
                    self::t('A solução deve ter pelo menos %d caracteres visíveis (atual: %d)'),
                    $minLength,
                    $actualLength
                );
            }
        }

        return $errors;
    }

    /**
     * Verifica presença de técnico atribuído via _actors, _users_id_assign ou glpi_tickets_users.
     */
    private static function hasTechnician(int $ticketsId, array $ticketFields, array $ticketInput): bool
    {
        if (isset($ticketInput['_actors']) && is_array($ticketInput['_actors'])) {
            $assign = isset($ticketInput['_actors']['assign']) && is_array($ticketInput['_actors']['assign'])
                ? $ticketInput['_actors']['assign']
                : [];
            if (self::hasActorInActorsArray($assign, 'User')) {
                return true;
            }
            return self::hasPositiveId($ticketInput['_users_id_assign'] ?? null);
        }

        if (self::hasPositiveId($ticketFields['_users_id_assign'] ?? null)) {
            return true;
        }

        return self::hasAssignedDbActor($ticketsId, 'glpi_tickets_users', 'users_id');
    }

    /**
     * Verifica presença de grupo técnico atribuído via _actors, _groups_id_assign ou glpi_groups_tickets.
     */
    private static function hasGroup(int $ticketsId, array $ticketFields, array $ticketInput): bool
    {
        if (isset($ticketInput['_actors']) && is_array($ticketInput['_actors'])) {
            $assign = isset($ticketInput['_actors']['assign']) && is_array($ticketInput['_actors']['assign'])
                ? $ticketInput['_actors']['assign']
                : [];
            if (self::hasActorInActorsArray($assign, 'Group')) {
                return true;
            }
            return self::hasPositiveId($ticketInput['_groups_id_assign'] ?? null);
        }

        if (self::hasPositiveId($ticketFields['_groups_id_assign'] ?? null)) {
            return true;
        }

        return self::hasAssignedDbActor($ticketsId, 'glpi_groups_tickets', 'groups_id');
    }

    /**
     * Verifica se o payload _actors['assign'] do GLPI 10+ contém ator do tipo informado.
     */
    private static function hasActorInActorsArray(array $assignActors, string $expectedItemtype): bool
    {
        foreach ($assignActors as $actor) {
            if (is_array($actor)) {
                $type = (string) ($actor['itemtype'] ?? '');
                $id   = (int) ($actor['items_id'] ?? 0);
                if ($type === $expectedItemtype && $id > 0) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Verifica se um valor escalar ou lista de IDs contém ao menos um ID positivo.
     *
     * @param mixed $value
     */
    private static function hasPositiveId($value): bool
    {
        if (is_array($value)) {
            foreach ($value as $entry) {
                if (is_scalar($entry) && (int) $entry > 0) {
                    return true;
                }
            }
            return false;
        }
        return is_scalar($value) && (int) $value > 0;
    }

    /**
     * Consulta tabela relacional de atores do chamado (type = 2 / assign).
     */
    private static function hasAssignedDbActor(int $ticketsId, string $table, string $column): bool
    {
        global $DB;

        if ($ticketsId <= 0 || !isset($DB) || !method_exists($DB, 'request')) {
            return false;
        }
        if (method_exists($DB, 'tableExists') && !$DB->tableExists($table)) {
            return false;
        }

        $it = $DB->request([
            'SELECT' => ['id', $column],
            'FROM'   => $table,
            'WHERE'  => [
                'tickets_id' => $ticketsId,
                'type'       => self::ACTOR_ASSIGN,
            ],
        ]);

        foreach ($it as $row) {
            if ((int) ($row[$column] ?? 0) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verifica se determinado usuário possui vínculo no chamado com o papel informado.
     */
    private static function hasTicketUser(int $ticketsId, int $usersId, int $type): bool
    {
        global $DB;

        if ($ticketsId <= 0 || $usersId <= 0 || !isset($DB) || !method_exists($DB, 'request')) {
            return false;
        }
        if (method_exists($DB, 'tableExists') && !$DB->tableExists('glpi_tickets_users')) {
            return false;
        }

        $it = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_tickets_users',
            'WHERE'  => [
                'tickets_id' => $ticketsId,
                'users_id'   => $usersId,
                'type'       => $type,
            ],
            'LIMIT'  => 1,
        ]);

        foreach ($it as $row) {
            return true;
        }

        return false;
    }

    /**
     * Verifica se o chamado possui ao menos uma tarefa cadastrada (não excluída, se houver is_deleted).
     */
    private static function hasTicketTask(int $ticketsId): bool
    {
        global $DB;

        if ($ticketsId <= 0 || !isset($DB) || !method_exists($DB, 'request')) {
            return false;
        }
        if (method_exists($DB, 'tableExists') && !$DB->tableExists('glpi_tickettasks')) {
            return false;
        }

        $where = ['tickets_id' => $ticketsId];
        if (method_exists($DB, 'fieldExists') && $DB->fieldExists('glpi_tickettasks', 'is_deleted')) {
            $where['is_deleted'] = 0;
        }

        $it = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_tickettasks',
            'WHERE'  => $where,
            'LIMIT'  => 1,
        ]);

        foreach ($it as $row) {
            return true;
        }

        return false;
    }

    /**
     * Busca o conteúdo da solução não recusada mais recente vinculada ao chamado.
     */
    private static function fetchLatestSolutionContent(int $ticketsId): string
    {
        global $DB;

        if ($ticketsId <= 0 || !isset($DB) || !method_exists($DB, 'request')) {
            return '';
        }
        if (method_exists($DB, 'tableExists') && !$DB->tableExists('glpi_itilsolutions')) {
            return '';
        }

        $it = $DB->request([
            'SELECT' => ['content'],
            'FROM'   => 'glpi_itilsolutions',
            'WHERE'  => [
                'itemtype' => 'Ticket',
                'items_id' => $ticketsId,
                'NOT'      => ['status' => self::SOLUTION_REFUSED],
            ],
            'ORDER'  => ['id DESC'],
            'LIMIT'  => 1,
        ]);

        foreach ($it as $row) {
            return (string) ($row['content'] ?? '');
        }

        return '';
    }

    /**
     * Cancela a gravação do item e registra mensagem de erro na sessão.
     *
     * @param string[] $errors
     */
    private static function blockItem(CommonDBTM $item, array $errors): void
    {
        $item->input = false;

        $prefix = self::t('Ticket Rules — ação bloqueada:');
        $msg    = htmlspecialchars($prefix . ' ' . implode('; ', $errors) . '.', ENT_QUOTES, 'UTF-8');

        if (class_exists('Session') && method_exists('Session', 'addMessageAfterRedirect')) {
            $errorLevel = defined('ERROR') ? ERROR : 3;
            Session::addMessageAfterRedirect($msg, true, $errorLevel);
        }
    }

    /**
     * Auxiliar de tradução com fallback caso __() não esteja carregada.
     */
    private static function t(string $text): string
    {
        return function_exists('__') ? __($text, 'ticketrules') : $text;
    }
}
