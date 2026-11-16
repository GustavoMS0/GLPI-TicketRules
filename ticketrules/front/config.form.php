<?php

/**
 * ------------------------------------------------------------------------
 * Ticket Rules - Página de configuração por entidade
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
    include_once __DIR__ . '/../../../inc/includes.php';
}

include_once __DIR__ . '/../inc/compat.class.php';
include_once __DIR__ . '/../inc/config.class.php';

/** @var DBmysql $DB */
global $DB;

Session::checkRight('config', READ);
$canUpdate = Session::haveRight('config', UPDATE);

$self = PluginTicketrulesCompat::webPath() . '/front/config.form.php';
$esc  = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

$defaultEid = Session::haveAccessToEntity(0) ? 0 : (int) ($_SESSION['glpiactive_entity'] ?? 0);

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) === 'POST') {
    Session::checkRight('config', UPDATE);

    if (!PluginTicketrulesCompat::requireSafePost()) {
        PluginTicketrulesCompat::denyAccess();
        return;
    }

    $eid = filter_var(
        $_POST['entities_id'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 0]]
    );
    if ($eid === false || !Session::haveAccessToEntity((int) $eid)) {
        PluginTicketrulesCompat::denyAccess();
        return;
    }
    $eid = (int) $eid;

    PluginTicketrulesCompat::normalizeInput();

    if (isset($_POST['delete_config'])) {
        PluginTicketrulesConfig::deleteConfig($eid);
        Session::addMessageAfterRedirect(
            __('Configuração específica removida. A entidade passa a herdar da entidade superior ou do padrão.', 'ticketrules'),
            true,
            INFO
        );
    } else {
        $saved = PluginTicketrulesConfig::saveConfig($eid, $_POST);
        if ($saved) {
            Session::addMessageAfterRedirect(
                __('Regras de chamados salvas com sucesso.', 'ticketrules'),
                true,
                INFO
            );
        } else {
            Session::addMessageAfterRedirect(
                __('Não foi possível salvar as regras de chamados.', 'ticketrules'),
                true,
                ERROR
            );
        }
    }

    Html::redirect($self . '?entities_id=' . $eid);
    return;
}

if (array_key_exists('entities_id', $_GET)) {
    $parsedGetEid = filter_var(
        $_GET['entities_id'],
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 0]]
    );
    $eid = $parsedGetEid !== false ? (int) $parsedGetEid : -1;
} else {
    $eid = $defaultEid;
}

if ($eid < 0 || !Session::haveAccessToEntity($eid)) {
    PluginTicketrulesCompat::denyAccess();
    return;
}

$own        = PluginTicketrulesConfig::getOwnConfig($eid);
$effective  = PluginTicketrulesConfig::resolveForEntity($eid);
$formValues = $own !== null ? $own : $effective;

$entityName = class_exists('Dropdown')
    ? PluginTicketrulesCompat::fromCore((string) Dropdown::getDropdownName('glpi_entities', $eid))
    : ('#' . $eid);

$profiles = [];
if (isset($DB) && method_exists($DB, 'request')) {
    $it = $DB->request([
        'SELECT' => ['id', 'name'],
        'FROM'   => 'glpi_profiles',
        'ORDER'  => ['name ASC'],
    ]);
    foreach ($it as $row) {
        $profiles[(int) $row['id']] = PluginTicketrulesCompat::fromCore((string) ($row['name'] ?? ''));
    }
}

$ticketTypes = [
    PluginTicketrulesConfigConstants::TICKET_TYPE_INCIDENT => __('Incidente', 'ticketrules'),
    PluginTicketrulesConfigConstants::TICKET_TYPE_REQUEST  => __('Requisição', 'ticketrules'),
];

$disabledAttr = $canUpdate ? '' : ' disabled="disabled"';

Html::header(__('Ticket Rules', 'ticketrules'), '', 'config', 'plugin');

echo '<div class="ticketrules-container">';

// Cabeçalho e resumo da entidade selecionada
echo '<div class="ticketrules-card ticketrules-header-card">';
echo '<div class="ticketrules-header-top">';
echo '<div>';
echo '<h2 class="ticketrules-title"><i class="ti ti-shield-check"></i> ' . $esc(__('Ticket Rules — Regras de Qualidade', 'ticketrules')) . '</h2>';
echo '<p class="ticketrules-subtitle">' . $esc(__('Defina campos obrigatórios e qualidade mínima da solução antes de solucionar ou fechar chamados.', 'ticketrules')) . '</p>';
echo '</div>';

if ($own !== null) {
    $badgeClass = (int) $own['is_active'] === 1 ? 'ticketrules-badge-active' : 'ticketrules-badge-inactive';
    $badgeText  = (int) $own['is_active'] === 1
        ? __('Regra própria ativa', 'ticketrules')
        : __('Regra própria inativa', 'ticketrules');
} elseif ($effective['inherited_from'] !== null) {
    $parentId   = (int) $effective['inherited_from'];
    $parentName = class_exists('Dropdown')
        ? PluginTicketrulesCompat::fromCore((string) Dropdown::getDropdownName('glpi_entities', $parentId))
        : ('#' . $parentId);
    $badgeClass = (int) $effective['is_active'] === 1 ? 'ticketrules-badge-inherited' : 'ticketrules-badge-inactive';
    $badgeText  = sprintf(__('Herdada de: %s', 'ticketrules'), $parentName);
} else {
    $badgeClass = 'ticketrules-badge-inactive';
    $badgeText  = __('Sem regra configurada (padrão inativo)', 'ticketrules');
}

echo '<span class="ticketrules-badge ' . $esc($badgeClass) . '">' . $esc($badgeText) . '</span>';
echo '</div>';

// Seletor de entidade (GET)
echo '<form method="get" action="' . $esc($self) . '" class="ticketrules-entity-bar">';
echo '<label for="dropdown_entities_id" class="ticketrules-entity-label">' . $esc(__('Entidade:', 'ticketrules')) . '</label>';
echo '<div class="ticketrules-entity-picker">';
if (class_exists('Entity') && method_exists('Entity', 'dropdown')) {
    Entity::dropdown([
        'name'      => 'entities_id',
        'value'     => $eid,
        'on_change' => 'this.form.submit()',
    ]);
} else {
    echo '<input type="number" name="entities_id" value="' . $esc($eid) . '" class="form-control">';
}
echo '</div>';
echo '<button type="submit" class="btn btn-outline-secondary btn-sm">' . $esc(__('Carregar', 'ticketrules')) . '</button>';
echo '</form>';
echo '</div>';

// Formulário principal de configuração (POST)
echo '<form method="post" action="' . $esc($self . '?entities_id=' . $eid) . '">';
echo '<input type="hidden" name="entities_id" value="' . $esc($eid) . '">';
echo '<input type="hidden" name="apply_to_types_present" value="1">';

// Card 1: Ativação e escopo
echo '<div class="ticketrules-card">';
echo '<h3 class="ticketrules-section-title"><i class="ti ti-adjustments"></i> ' . $esc(__('Ativação e Escopo', 'ticketrules')) . ' — ' . $esc($entityName) . '</h3>';

echo '<div class="ticketrules-grid">';
$renderToggle = static function (string $name, string $label, string $help, int $checked) use ($esc, $disabledAttr): void {
    $id      = 'tr_' . $name;
    $checkedAttr = $checked === 1 ? ' checked="checked"' : '';
    echo '<label class="ticketrules-check-card" for="' . $esc($id) . '">';
    echo '<input type="checkbox" class="form-check-input" id="' . $esc($id) . '" name="' . $esc($name) . '" value="1"' . $checkedAttr . $disabledAttr . '>';
    echo '<span><strong>' . $esc($label) . '</strong><small>' . $esc($help) . '</small></span>';
    echo '</label>';
};

$renderToggle(
    'is_active',
    __('Ativar regras nesta entidade', 'ticketrules'),
    __('Quando desmarcado, nenhuma validação do plugin é aplicada para esta entidade.', 'ticketrules'),
    (int) ($formValues['is_active'] ?? 0)
);
$renderToggle(
    'is_recursive',
    __('Aplicar recursivamente às entidades filhas', 'ticketrules'),
    __('Entidades abaixo sem regra própria herdarão esta configuração.', 'ticketrules'),
    (int) ($formValues['is_recursive'] ?? 1)
);
$renderToggle(
    'apply_on_solve',
    __('Validar ao solucionar chamado', 'ticketrules'),
    __('Aplica as regras ao adicionar solução ou mudar status diretamente para Solucionado.', 'ticketrules'),
    (int) ($formValues['apply_on_solve'] ?? 1)
);
$renderToggle(
    'apply_on_close',
    __('Validar ao fechar chamado', 'ticketrules'),
    __('Aplica ao fechar diretamente ou na interface central; aprovação do requerente nunca é bloqueada.', 'ticketrules'),
    (int) ($formValues['apply_on_close'] ?? 1)
);
echo '</div>';

echo '<div class="ticketrules-subsection">';
echo '<span class="ticketrules-field-label">' . $esc(__('Tipos de chamado abrangidos', 'ticketrules')) . '</span>';
echo '<div class="ticketrules-pills">';
$selectedTypes = array_map('intval', (array) ($formValues['apply_to_types'] ?? []));
foreach ($ticketTypes as $typeId => $typeLabel) {
    $checked = in_array((int) $typeId, $selectedTypes, true) ? ' checked="checked"' : '';
    $id      = 'tr_type_' . $typeId;
    echo '<label class="ticketrules-pill" for="' . $esc($id) . '">';
    echo '<input type="checkbox" class="form-check-input" id="' . $esc($id) . '" name="apply_to_types[]" value="' . $esc($typeId) . '"' . $checked . $disabledAttr . '>';
    echo '<span>' . $esc($typeLabel) . '</span>';
    echo '</label>';
}
echo '</div>';
echo '</div>';
echo '</div>';

// Card 2: Campos obrigatórios no chamado
echo '<div class="ticketrules-card">';
echo '<h3 class="ticketrules-section-title"><i class="ti ti-list-check"></i> ' . $esc(__('Campos Obrigatórios do Chamado', 'ticketrules')) . '</h3>';
echo '<div class="ticketrules-grid">';

$fieldRules = [
    ['require_category', __('Categoria obrigatória', 'ticketrules'), __('Exige categoria ITIL preenchida no chamado.', 'ticketrules')],
    ['require_type', __('Tipo obrigatório', 'ticketrules'), __('Exige tipo do chamado definido (Incidente ou Requisição).', 'ticketrules')],
    ['require_technician', __('Técnico atribuído obrigatório', 'ticketrules'), __('Exige ao menos um técnico atribuído ao chamado.', 'ticketrules')],
    ['require_group', __('Grupo técnico obrigatório', 'ticketrules'), __('Exige ao menos um grupo técnico atribuído.', 'ticketrules')],
    ['require_location', __('Localização obrigatória', 'ticketrules'), __('Exige localização informada no chamado.', 'ticketrules')],
    ['require_requesttype', __('Origem da requisição obrigatória', 'ticketrules'), __('Exige preenchimento da origem (e-mail, telefone, portal etc.).', 'ticketrules')],
    ['require_task_or_time', __('Tarefa ou tempo trabalhado obrigatório', 'ticketrules'), __('Exige ao menos uma tarefa registrada ou tempo total > 0.', 'ticketrules')],
];

foreach ($fieldRules as $rule) {
    $renderToggle($rule[0], $rule[1], $rule[2], (int) ($formValues[$rule[0]] ?? 0));
}

echo '</div>';
echo '</div>';

// Card 3: Qualidade da solução e perfis isentos
echo '<div class="ticketrules-card">';
echo '<h3 class="ticketrules-section-title"><i class="ti ti-file-text"></i> ' . $esc(__('Qualidade da Solução e Exceções', 'ticketrules')) . '</h3>';

echo '<div class="ticketrules-length-row">';
echo '<label for="tr_min_solution_length" class="form-label fw-semibold">' . $esc(__('Tamanho mínimo da solução (caracteres visíveis)', 'ticketrules')) . '</label>';
echo '<input type="number" min="0" max="10000" id="tr_min_solution_length" name="min_solution_length" class="form-control ticketrules-number-input" value="' . $esc((int) ($formValues['min_solution_length'] ?? 0)) . '"' . $disabledAttr . '>';
echo '<p class="ticketrules-help">' . $esc(__('Tags HTML, entidades vazias (como &nbsp;) e espaços repetidos são desconsiderados na contagem. Informe 0 para não exigir mínimo.', 'ticketrules')) . '</p>';
echo '</div>';

echo '<div class="ticketrules-subsection">';
echo '<span class="ticketrules-field-label">' . $esc(__('Perfis isentos das regras', 'ticketrules')) . '</span>';
echo '<p class="ticketrules-help">' . $esc(__('Usuários atuando com um dos perfis marcados abaixo podem solucionar e fechar chamados sem bloqueio.', 'ticketrules')) . '</p>';

if (!empty($profiles)) {
    $selectedProfiles = array_map('intval', (array) ($formValues['exempt_profiles'] ?? []));
    echo '<div class="ticketrules-profiles-grid">';
    foreach ($profiles as $pid => $pname) {
        $checked = in_array((int) $pid, $selectedProfiles, true) ? ' checked="checked"' : '';
        $id      = 'tr_profile_' . $pid;
        echo '<label class="ticketrules-pill" for="' . $esc($id) . '">';
        echo '<input type="checkbox" class="form-check-input" id="' . $esc($id) . '" name="exempt_profiles[]" value="' . $esc($pid) . '"' . $checked . $disabledAttr . '>';
        echo '<span>' . $esc($pname) . '</span>';
        echo '</label>';
    }
    echo '</div>';
} else {
    echo '<p class="text-muted mb-0">' . $esc(__('Nenhum perfil encontrado.', 'ticketrules')) . '</p>';
}
echo '</div>';
echo '</div>';

// Barra de ações
if ($canUpdate) {
    echo '<div class="ticketrules-actions">';
    echo '<button type="submit" name="save_config" value="1" class="btn btn-primary"><i class="ti ti-device-floppy"></i> ' . $esc(__('Salvar regras', 'ticketrules')) . '</button>';
    if ($own !== null) {
        echo '<button type="submit" name="delete_config" value="1" class="btn btn-outline-danger"><i class="ti ti-trash"></i> ' . $esc(__('Remover regra desta entidade', 'ticketrules')) . '</button>';
    }
    echo '</div>';
}

Html::closeForm();

echo '</div>';

Html::footer();
