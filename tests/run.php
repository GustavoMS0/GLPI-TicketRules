<?php

/**
 * ------------------------------------------------------------------------
 * Ticket Rules - Suíte de testes unitários standalone
 *
 * Copyright (C) 2026 by G. Martins (GPLv3+)
 * ------------------------------------------------------------------------
 */

require_once __DIR__ . '/bootstrap.php';

function assertSame($expected, $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            sprintf(
                "Falha em [%s]: esperado %s, obtido %s",
                $label,
                var_export($expected, true),
                var_export($actual, true)
            )
        );
    }
}

function assertTrue($condition, string $label): void
{
    if ($condition !== true) {
        throw new RuntimeException(sprintf("Falha em [%s]: esperado true", $label));
    }
}

function resetEnvironment(): void
{
    global $DB;
    $DB->reset();
    Session::reset();
    Plugin::$webDirCalled = false;
    if (property_exists('Html', 'rightErrorDisplayed')) {
        Html::$rightErrorDisplayed = false;
    }
    $GLOBALS['TEST_CLI_MODE'] = false;
    $_GET    = [];
    $_POST   = [];
    $_SERVER = [];
    plugin_ticketrules_install();
}

function test_visible_length_strips_html_and_entities(): void
{
    resetEnvironment();

    assertSame(0, PluginTicketrulesValidator::visibleLength(''), 'string vazia');
    assertSame(0, PluginTicketrulesValidator::visibleLength('<p>   &nbsp;  <br></p>'), 'somente tags e nbsp');
    assertSame(1, PluginTicketrulesValidator::visibleLength("\xFF<p>\xc2\xa0  </p>"), 'nbsp removido mesmo com UTF-8 invalido no fallback');
    assertSame(10, PluginTicketrulesValidator::visibleLength('<div><span>&lt;ok&gt;</span>   <b>teste</b></div>'), 'entidades decodificadas e colapso de espacos');
    assertSame(24, PluginTicketrulesValidator::visibleLength("  <p>Solução&nbsp;&amp;&nbsp;teste\n\t aplicado</p>  "), 'multibyte UTF-8 e whitespace');
}

function test_blocks_solution_when_missing_category_or_short_text(): void
{
    global $DB;
    resetEnvironment();

    PluginTicketrulesConfig::saveConfig(0, [
        'is_active'           => 1,
        'is_recursive'        => 1,
        'require_category'    => 1,
        'min_solution_length' => 20,
        'apply_on_solve'      => 1,
        'apply_on_close'      => 1,
        'apply_to_types'      => [1, 2],
    ]);

    // Chamado sem categoria
    $DB->seed('glpi_tickets', [[
        'id'                => 101,
        'entities_id'       => 0,
        'status'            => Ticket::ASSIGNED,
        'type'              => Ticket::INCIDENT_TYPE,
        'itilcategories_id' => 0,
        'locations_id'      => 0,
        'requesttypes_id'   => 0,
        'actiontime'        => 0,
    ]]);

    $solMissingCat = new ITILSolution();
    $solMissingCat->input = [
        'itemtype' => 'Ticket',
        'items_id' => 101,
        'content'  => '<p>Reiniciado o serviço de impressão e validado com o usuário.</p>',
    ];
    PluginTicketrulesValidator::preItemAddSolution($solMissingCat);
    assertSame(false, $solMissingCat->input, 'bloqueia solucao quando falta categoria');
    assertTrue(count(Session::$messages) > 0 && Session::$messages[0]['type'] === ERROR, 'registra erro de categoria na sessao');

    // Chamado com categoria, mas solucao curta (ignorando tags/nbsp)
    Session::$messages = [];
    $DB->update('glpi_tickets', ['itilcategories_id' => 5], ['id' => 101]);

    $solShort = new ITILSolution();
    $solShort->input = [
        'itemtype' => 'Ticket',
        'items_id' => 101,
        'content'  => '<p><b>Resolvido</b> &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</p>',
    ];
    PluginTicketrulesValidator::preItemAddSolution($solShort);
    assertSame(false, $solShort->input, 'bloqueia solucao com texto visivel curto');
    assertTrue(count(Session::$messages) > 0 && Session::$messages[0]['type'] === ERROR, 'registra erro de tamanho minimo na sessao');

    // Chamado com categoria e solucao valida
    Session::$messages = [];
    $solValid = new ITILSolution();
    $solValid->input = [
        'itemtype' => 'Ticket',
        'items_id' => 101,
        'content'  => '<p>Substituído cabo de rede e validado o acesso do colaborador.</p>',
    ];
    PluginTicketrulesValidator::preItemAddSolution($solValid);
    assertTrue(is_array($solValid->input), 'permite solucao quando todos os criterios passam');
    assertSame(0, count(Session::$messages), 'nenhum erro ao permitir solucao valida');
}

function test_allows_requester_approval_solved_to_closed(): void
{
    global $DB;
    resetEnvironment();

    PluginTicketrulesConfig::saveConfig(0, [
        'is_active'           => 1,
        'is_recursive'        => 1,
        'require_category'    => 1,
        'require_technician'  => 1,
        'min_solution_length' => 50,
        'apply_on_solve'      => 1,
        'apply_on_close'      => 1,
        'apply_to_types'      => [1, 2],
    ]);

    $DB->seed('glpi_tickets', [[
        'id'                => 201,
        'entities_id'       => 0,
        'status'            => Ticket::SOLVED,
        'type'              => Ticket::DEMAND_TYPE,
        'itilcategories_id' => 0,
        'locations_id'      => 0,
        'requesttypes_id'   => 0,
        'actiontime'        => 0,
    ]]);

    $DB->seed('glpi_itilsolutions', [[
        'id'                => 11,
        'itemtype'          => 'Ticket',
        'items_id'          => 201,
        'content'           => '<p>Ajustado.</p>',
        'status'            => 2,
        'users_id_approval' => 0,
    ]]);

    // 1) Aprovacao/recusa de ITILSolution sem campo content nunca falha
    $solUpdate = new ITILSolution();
    $solUpdate->fields = [
        'id'       => 11,
        'itemtype' => 'Ticket',
        'items_id' => 201,
        'content'  => '<p>Ajustado.</p>',
        'status'   => 2,
    ];
    $solUpdate->input = [
        'id'     => 11,
        'status' => 3,
    ];
    PluginTicketrulesValidator::preItemUpdateSolution($solUpdate);
    assertTrue(is_array($solUpdate->input), 'permite atualizacao de status de ITILSolution sem content');

    // 2) Requerente na interface self-service (helpdesk) fechando SOLVED -> CLOSED nunca e bloqueado
    $_SESSION['glpiactiveprofile'] = ['id' => 1, 'interface' => 'helpdesk'];
    $ticketSelfService = new Ticket();
    $ticketSelfService->getFromDB(201);
    $ticketSelfService->input = ['id' => 201, 'status' => Ticket::CLOSED];
    PluginTicketrulesValidator::preItemUpdateTicket($ticketSelfService);
    assertTrue(is_array($ticketSelfService->input), 'permite fechamento SOLVED->CLOSED pela interface self-service');

    // 3) Nao-requerente forjando _accepted / add_close em central NAO pode burlar apply_on_close
    $_SESSION['glpiactiveprofile'] = ['id' => 3, 'interface' => 'central'];
    Session::$loginUserId = 88;
    $_POST['add_close'] = '1';
    $_POST['approve']   = '1';
    $ticketForged = new Ticket();
    $ticketForged->getFromDB(201);
    $ticketForged->input = [
        'id'                 => 201,
        'status'             => Ticket::CLOSED,
        '_accepted'          => 1,
        '_solution_approval' => 1,
    ];
    PluginTicketrulesValidator::preItemUpdateTicket($ticketForged);
    assertSame(false, $ticketForged->input, 'nao-requerente forjando flags de aprovacao continua bloqueado');
    $_POST = [];

    // 4) Requerente com perfil central aprovando solucao (status=3 no banco) nao e bloqueado
    Session::$loginUserId = 99;
    $DB->seed('glpi_tickets_users', [[
        'id'         => 1,
        'tickets_id' => 201,
        'users_id'   => 99,
        'type'       => 1,
    ]]);
    $DB->update('glpi_itilsolutions', ['status' => 3, 'users_id_approval' => 99], ['id' => 11]);

    $ticketApprovalCentral = new Ticket();
    $ticketApprovalCentral->getFromDB(201);
    $ticketApprovalCentral->input = ['id' => 201, 'status' => Ticket::CLOSED];
    PluginTicketrulesValidator::preItemUpdateTicket($ticketApprovalCentral);
    assertTrue(is_array($ticketApprovalCentral->input), 'permite aprovacao SOLVED->CLOSED pelo requerente em central');

    // 5) Requerente com solucao ainda pendente (status=2) mesmo com add_close forjado deve bloquear
    $DB->update('glpi_itilsolutions', ['status' => 2, 'users_id_approval' => 0], ['id' => 11]);
    $_POST['add_close'] = '1';
    $ticketPendingClose = new Ticket();
    $ticketPendingClose->getFromDB(201);
    $ticketPendingClose->input = ['id' => 201, 'status' => Ticket::CLOSED, '_accepted' => 1];
    PluginTicketrulesValidator::preItemUpdateTicket($ticketPendingClose);
    assertSame(false, $ticketPendingClose->input, 'bloqueia fechamento quando solucao existente no banco nao foi aceita');
    $_POST = [];

    // 6) Tecnico fechando manualmente SOLVED -> CLOSED em central sem aprovacao e sem requisitos e bloqueado
    Session::$loginUserId = 2;
    $ticketCentralClose = new Ticket();
    $ticketCentralClose->getFromDB(201);
    $ticketCentralClose->input = ['id' => 201, 'status' => Ticket::CLOSED];
    PluginTicketrulesValidator::preItemUpdateTicket($ticketCentralClose);
    assertSame(false, $ticketCentralClose->input, 'bloqueia fechamento manual em central quando faltam requisitos');
}

function test_blocks_direct_status_solve_without_valid_solution_or_task(): void
{
    global $DB;
    resetEnvironment();

    PluginTicketrulesConfig::saveConfig(0, [
        'is_active'            => 1,
        'is_recursive'         => 1,
        'require_technician'   => 1,
        'require_group'        => 1,
        'require_location'     => 1,
        'require_requesttype'  => 1,
        'require_task_or_time' => 1,
        'min_solution_length'  => 15,
        'apply_on_solve'       => 1,
        'apply_on_close'       => 1,
        'apply_to_types'       => [1, 2],
    ]);

    $DB->seed('glpi_tickets', [[
        'id'                => 301,
        'entities_id'       => 0,
        'status'            => Ticket::ASSIGNED,
        'type'              => Ticket::INCIDENT_TYPE,
        'itilcategories_id' => 2,
        'locations_id'      => 4,
        'requesttypes_id'   => 1,
        'actiontime'        => 0,
    ]]);
    $DB->seed('glpi_tickets_users', [[
        'tickets_id' => 301,
        'users_id'   => 10,
        'type'       => 2,
    ]]);
    $DB->seed('glpi_groups_tickets', [[
        'tickets_id' => 301,
        'groups_id'  => 7,
        'type'       => 2,
    ]]);

    // Sem tarefa/tempo e sem solucao
    $ticketSolve = new Ticket();
    $ticketSolve->getFromDB(301);
    $ticketSolve->input = ['id' => 301, 'status' => Ticket::SOLVED];
    PluginTicketrulesValidator::preItemUpdateTicket($ticketSolve);
    assertSame(false, $ticketSolve->input, 'bloqueia passagem direta para SOLVED sem tarefa nem solucao');

    // Passagem direta <5 -> CLOSED tambem bloqueia
    $ticketDirectClose = new Ticket();
    $ticketDirectClose->getFromDB(301);
    $ticketDirectClose->input = ['id' => 301, 'status' => Ticket::CLOSED];
    PluginTicketrulesValidator::preItemUpdateTicket($ticketDirectClose);
    assertSame(false, $ticketDirectClose->input, 'bloqueia passagem direta para CLOSED sem tarefa nem solucao');

    // Campo transiente input['solution'] NAO pode burlar ausencia de solucao persistida
    $DB->seed('glpi_tickettasks', [[
        'tickets_id' => 301,
        'content'    => 'Atendimento remoto',
        'actiontime' => 600,
    ]]);
    $ticketTransientSol = new Ticket();
    $ticketTransientSol->getFromDB(301);
    $ticketTransientSol->input = [
        'id'       => 301,
        'status'   => Ticket::SOLVED,
        'solution' => '<p>Texto transiente longo que nao e gravado na tabela de chamados.</p>',
    ];
    PluginTicketrulesValidator::preItemUpdateTicket($ticketTransientSol);
    assertSame(false, $ticketTransientSol->input, 'rejeita bypass por input[solution] transiente sem registro em glpi_itilsolutions');

    // Solucao recusada (status = 4) deve ser ignorada
    $DB->seed('glpi_itilsolutions', [[
        'itemtype' => 'Ticket',
        'items_id' => 301,
        'content'  => '<p>Solucao recusada anteriormente mas com texto grande suficiente.</p>',
        'status'   => 4,
    ]]);
    $ticketRefusedOnly = new Ticket();
    $ticketRefusedOnly->getFromDB(301);
    $ticketRefusedOnly->input = ['id' => 301, 'status' => Ticket::SOLVED];
    PluginTicketrulesValidator::preItemUpdateTicket($ticketRefusedOnly);
    assertSame(false, $ticketRefusedOnly->input, 'exclui solucao recusada (status=4) da validacao');

    // Payload _actors esvaziando tecnicos/grupos deve bloquear mesmo com linhas antigas no banco
    $DB->seed('glpi_itilsolutions', [[
        'itemtype' => 'Ticket',
        'items_id' => 301,
        'content'  => '<p>Driver atualizado e impressora testada com sucesso.</p>',
        'status'   => 2,
    ]]);
    $ticketClearedActors = new Ticket();
    $ticketClearedActors->getFromDB(301);
    $ticketClearedActors->input = [
        'id'      => 301,
        'status'  => Ticket::SOLVED,
        '_actors' => ['requester' => [['itemtype' => 'User', 'items_id' => 99]]],
    ];
    PluginTicketrulesValidator::preItemUpdateTicket($ticketClearedActors);
    assertSame(false, $ticketClearedActors->input, 'bloqueia quando _actors remove atribuicoes antigas');

    // Agora com solucao valida e sem remocao de atores
    $ticketValid = new Ticket();
    $ticketValid->getFromDB(301);
    $ticketValid->input = ['id' => 301, 'status' => Ticket::SOLVED];
    PluginTicketrulesValidator::preItemUpdateTicket($ticketValid);
    assertTrue(is_array($ticketValid->input), 'permite passagem para SOLVED com tarefa e solucao valida');

    // Criacao direta de Ticket em SOLVED/CLOSED bloqueia se faltam requisitos e permite se valido
    $ticketNewInvalid = new Ticket();
    $ticketNewInvalid->input = [
        'entities_id'       => 0,
        'status'            => Ticket::CLOSED,
        'type'              => Ticket::INCIDENT_TYPE,
        'itilcategories_id' => 2,
        'users_id_assign'   => 10,
        'groups_id_assign'  => 7,
        'locations_id'      => 4,
        'requesttypes_id'   => 1,
        'actiontime'        => 300,
    ];
    PluginTicketrulesValidator::preItemAddTicket($ticketNewInvalid);
    assertSame(false, $ticketNewInvalid->input, 'bloqueia Ticket::add direto em CLOSED sem _users_id_assign/solucao');

    PluginTicketrulesConfig::saveConfig(0, [
        'is_active'           => 1,
        'is_recursive'        => 1,
        'require_technician'  => 1,
        'min_solution_length' => 0,
        'apply_on_solve'      => 1,
        'apply_on_close'      => 1,
    ]);
    $ticketNewValid = new Ticket();
    $ticketNewValid->input = [
        'entities_id'      => 0,
        'status'           => Ticket::SOLVED,
        'type'             => Ticket::INCIDENT_TYPE,
        '_users_id_assign' => 10,
    ];
    PluginTicketrulesValidator::preItemAddTicket($ticketNewValid);
    assertTrue(is_array($ticketNewValid->input), 'permite Ticket::add em SOLVED quando regras sao atendidas');
}

function test_skips_cli_and_exempt_profiles(): void
{
    global $DB;
    resetEnvironment();

    PluginTicketrulesConfig::saveConfig(0, [
        'is_active'           => 1,
        'is_recursive'        => 1,
        'require_category'    => 1,
        'min_solution_length' => 100,
        'apply_on_solve'      => 1,
        'apply_on_close'      => 1,
        'apply_to_types'      => [Ticket::INCIDENT_TYPE],
        'exempt_profiles'     => [4],
    ]);

    $DB->seed('glpi_tickets', [
        [
            'id'                => 401,
            'entities_id'       => 0,
            'status'            => Ticket::ASSIGNED,
            'type'              => Ticket::INCIDENT_TYPE,
            'itilcategories_id' => 0,
        ],
        [
            'id'                => 402,
            'entities_id'       => 0,
            'status'            => Ticket::ASSIGNED,
            'type'              => Ticket::DEMAND_TYPE,
            'itilcategories_id' => 0,
        ],
        [
            'id'                => 403,
            'entities_id'       => 0,
            'status'            => Ticket::ASSIGNED,
            'type'              => 0,
            'itilcategories_id' => 0,
        ],
    ]);

    // 1) CLI ativo
    $GLOBALS['TEST_CLI_MODE'] = true;
    $solCli = new ITILSolution();
    $solCli->input = ['itemtype' => 'Ticket', 'items_id' => 401, 'content' => 'ok'];
    PluginTicketrulesValidator::preItemAddSolution($solCli);
    assertTrue(is_array($solCli->input), 'ignora validacao em execucao CLI');

    // 2) Session::isCron() ativo mesmo com loginUserId > 0
    $GLOBALS['TEST_CLI_MODE'] = false;
    Session::$loginUserId     = 2;
    Session::$cronMode        = true;
    $solIsCron = new ITILSolution();
    $solIsCron->input = ['itemtype' => 'Ticket', 'items_id' => 401, 'content' => 'ok'];
    PluginTicketrulesValidator::preItemAddSolution($solIsCron);
    assertTrue(is_array($solIsCron->input), 'ignora validacao quando Session::isCron() e verdadeiro');
    Session::$cronMode = false;

    // 3) glpicronuserrunning na sessao
    $_SESSION['glpicronuserrunning'] = 'cron_task';
    $solCronSess = new ITILSolution();
    $solCronSess->input = ['itemtype' => 'Ticket', 'items_id' => 401, 'content' => 'ok'];
    PluginTicketrulesValidator::preItemAddSolution($solCronSess);
    assertTrue(is_array($solCronSess->input), 'ignora validacao quando glpicronuserrunning esta ativo');
    unset($_SESSION['glpicronuserrunning']);

    // 4) Sem usuario logado
    Session::$loginUserId = 0;
    $solCron = new ITILSolution();
    $solCron->input = ['itemtype' => 'Ticket', 'items_id' => 401, 'content' => 'ok'];
    PluginTicketrulesValidator::preItemAddSolution($solCron);
    assertTrue(is_array($solCron->input), 'ignora validacao sem usuario interativo');

    // 5) Perfil isento
    Session::$loginUserId         = 5;
    $_SESSION['glpiactiveprofile'] = ['id' => 4, 'interface' => 'central'];
    $solExempt = new ITILSolution();
    $solExempt->input = ['itemtype' => 'Ticket', 'items_id' => 401, 'content' => 'ok'];
    PluginTicketrulesValidator::preItemAddSolution($solExempt);
    assertTrue(is_array($solExempt->input), 'ignora validacao para perfil isento');

    // 6) Tipo de chamado fora de apply_to_types (Requisicao quando so Incidente esta ativo)
    $_SESSION['glpiactiveprofile'] = ['id' => 6, 'interface' => 'central'];
    $solOtherType = new ITILSolution();
    $solOtherType->input = ['itemtype' => 'Ticket', 'items_id' => 402, 'content' => 'ok'];
    PluginTicketrulesValidator::preItemAddSolution($solOtherType);
    assertTrue(is_array($solOtherType->input), 'ignora tipo de chamado nao selecionado');

    // 7) Chamado com type = 0 NAO pode burlar require_category ou require_type
    $solTypeZero = new ITILSolution();
    $solTypeZero->input = ['itemtype' => 'Ticket', 'items_id' => 403, 'content' => str_repeat('a', 120)];
    PluginTicketrulesValidator::preItemAddSolution($solTypeZero);
    assertSame(false, $solTypeZero->input, 'type=0 nao burla regras quando ha tipos habilitados');

    PluginTicketrulesConfig::saveConfig(0, [
        'is_active'              => 1,
        'require_type'           => 1,
        'apply_on_solve'         => 1,
        'apply_to_types_present' => 1,
    ]);
    $solRequireType = new ITILSolution();
    $solRequireType->input = ['itemtype' => 'Ticket', 'items_id' => 403, 'content' => 'ok'];
    PluginTicketrulesValidator::preItemAddSolution($solRequireType);
    assertSame(false, $solRequireType->input, 'require_type=1 bloqueia type=0 mesmo com apply_to_types vazio');

    // 8) Perfil nao isento + tipo aplicavel deve bloquear
    PluginTicketrulesConfig::saveConfig(0, [
        'is_active'        => 1,
        'require_category' => 1,
        'apply_on_solve'   => 1,
        'apply_to_types'   => [Ticket::INCIDENT_TYPE],
        'exempt_profiles'  => [4],
    ]);
    $solBlocked = new ITILSolution();
    $solBlocked->input = ['itemtype' => 'Ticket', 'items_id' => 401, 'content' => 'ok'];
    PluginTicketrulesValidator::preItemAddSolution($solBlocked);
    assertSame(false, $solBlocked->input, 'bloqueia perfil nao isento e tipo aplicavel');
}

function test_entity_recursive_inheritance_and_child_override(): void
{
    global $DB;
    resetEnvironment();

    // Hierarquia: 0 -> 1 -> 2 -> 3
    $DB->seed('glpi_entities', [
        ['id' => 0, 'entities_id' => -1, 'name' => 'Root'],
        ['id' => 1, 'entities_id' => 0, 'name' => 'Filial A'],
        ['id' => 2, 'entities_id' => 1, 'name' => 'Setor A1'],
        ['id' => 3, 'entities_id' => 2, 'name' => 'Equipe A1-X'],
    ]);

    // Sem regras: preset padrao inativo
    $empty = PluginTicketrulesConfig::resolveForEntity(3);
    assertSame(0, $empty['is_active'], 'preset padrao inativo quando nao ha regras');

    // Raiz recursiva
    PluginTicketrulesConfig::saveConfig(0, [
        'is_active'           => 1,
        'is_recursive'        => 1,
        'require_category'    => 1,
        'min_solution_length' => 20,
    ]);
    $fromRoot = PluginTicketrulesConfig::resolveForEntity(3);
    assertSame(1, $fromRoot['is_active'], 'entidade 3 herda ativacao da raiz');
    assertSame(20, $fromRoot['min_solution_length'], 'entidade 3 herda tamanho minimo 20 da raiz');
    assertSame(0, $fromRoot['inherited_from'], 'inherited_from aponta para entidade 0');

    // Entidade 1 com regra propria NAO recursiva
    PluginTicketrulesConfig::saveConfig(1, [
        'is_active'           => 1,
        'is_recursive'        => 0,
        'require_technician'  => 1,
        'min_solution_length' => 45,
    ]);
    $own1 = PluginTicketrulesConfig::resolveForEntity(1);
    assertSame(45, $own1['min_solution_length'], 'entidade 1 usa regra propria mesmo com is_recursive=0');
    assertSame(null, $own1['inherited_from'], 'entidade 1 nao e herdada');

    $skip1 = PluginTicketrulesConfig::resolveForEntity(2);
    assertSame(20, $skip1['min_solution_length'], 'entidade 2 ignora pai 1 nao recursivo e herda raiz');
    assertSame(0, $skip1['inherited_from'], 'entidade 2 herda de 0');

    // Entidade 2 passa a ter regra recursiva: entidade 3 deve herdar do pai mais proximo (2)
    PluginTicketrulesConfig::saveConfig(2, [
        'is_active'           => 1,
        'is_recursive'        => 1,
        'require_group'       => 1,
        'min_solution_length' => 12,
    ]);
    $fromParent2 = PluginTicketrulesConfig::resolveForEntity(3);
    assertSame(12, $fromParent2['min_solution_length'], 'entidade 3 prioriza ancestral recursivo mais proximo (2)');
    assertSame(1, $fromParent2['require_group'], 'entidade 3 recebe require_group=1 da entidade 2');
    assertSame(2, $fromParent2['inherited_from'], 'inherited_from aponta para entidade 2');

    // Arrays aninhados em apply_to_types / exempt_profiles sao ignorados com seguranca
    PluginTicketrulesConfig::saveConfig(2, [
        'is_active'       => 1,
        'is_recursive'    => 1,
        'apply_to_types'  => [['1'], 2],
        'exempt_profiles' => [['9'], 5],
    ]);
    $sanitized = PluginTicketrulesConfig::getOwnConfig(2);
    assertSame([2], $sanitized['apply_to_types'], 'saveConfig ignora valores nao escalares em apply_to_types');
    assertSame([5], $sanitized['exempt_profiles'], 'saveConfig ignora valores nao escalares em exempt_profiles');

    // Remover regra da entidade 2 faz entidade 3 voltar para a raiz
    PluginTicketrulesConfig::deleteConfig(2);
    $afterDelete = PluginTicketrulesConfig::resolveForEntity(3);
    assertSame(0, $afterDelete['inherited_from'], 'apos remover regra de 2, entidade 3 volta a herdar de 0');
}

function runIsolatedPhpAssertion(array $env, string $code): void
{
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $cmdEnv = array_merge($_ENV, $env);
    foreach ($_SERVER as $k => $v) {
        if (is_string($v) && !isset($cmdEnv[$k])) {
            $cmdEnv[$k] = $v;
        }
    }

    $oneLineCode = trim((string) preg_replace('/\s+/', ' ', $code));
    $process     = proc_open([PHP_BINARY, '-r', $oneLineCode], $descriptorSpec, $pipes, dirname(__DIR__), $cmdEnv);
    if (!is_resource($process)) {
        throw new RuntimeException('Falha ao iniciar subprocesso PHP');
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if ($exitCode !== 0) {
        throw new RuntimeException(
            sprintf("Subprocesso falhou (codigo %d). STDOUT: %s | STDERR: %s", $exitCode, $stdout, $stderr)
        );
    }
}

function test_typed_and_untyped_rightname_compat(): void
{
    // 1) GLPI 10 (CommonGLPI::$rightname sem tipo, fromCore preserva barras invertidas)
    runIsolatedPhpAssertion(
        [
            'TICKETRULES_TEST_TYPED_RIGHTNAME' => '0',
            'TICKETRULES_TEST_GLPI_VERSION'    => '10.0.18',
        ],
        '
        require "tests/bootstrap.php";
        if (PluginTicketrulesCompat::typedRightname() !== false) { exit(1); }
        $ref = new ReflectionProperty("PluginTicketrulesConfig", "rightname");
        if ($ref->hasType()) { exit(2); }
        if (PluginTicketrulesConfig::$rightname !== "config") { exit(3); }
        if (PluginTicketrulesCompat::glpiMajor() !== 10) { exit(4); }
        if (PluginTicketrulesCompat::assetPath("css/ticketrules.css") !== "public/css/ticketrules.css") { exit(5); }
        if ($GLOBALS["PLUGIN_HOOKS"]["add_css"]["ticketrules"][0] !== "public/css/ticketrules.css") { exit(6); }
        if (PluginTicketrulesCompat::fromCore("&#60;p&#62;C:\\temp\\ok&#60;/p&#62;") !== "<p>C:\\temp\\ok</p>") { exit(7); }
        if (PluginTicketrulesCompat::fromSanitizedInput("&#60;p&#62;C:\\\\temp\\\\ok&#60;/p&#62;") !== "<p>C:\\temp\\ok</p>") { exit(8); }
        if (PluginTicketrulesValidator::visibleLength("&#60;p&#62;C:\\temp&#60;/p&#62;") !== 7) { exit(9); }
        if (PluginTicketrulesCompat::webPath() !== "/plugins/ticketrules") { exit(10); }
        if (Plugin::$webDirCalled !== true) { exit(11); }
        PluginTicketrulesCompat::denyAccess();
        if (Html::$rightErrorDisplayed !== true) { exit(12); }
        exit(0);
        '
    );

    // 2) GLPI 11 (CommonGLPI::$rightname tipado, sem Plugin::getWebDir depreciado)
    runIsolatedPhpAssertion(
        [
            'TICKETRULES_TEST_TYPED_RIGHTNAME' => '1',
            'TICKETRULES_TEST_GLPI_VERSION'    => '11.0.0',
        ],
        '
        require "tests/bootstrap.php";
        if (PluginTicketrulesCompat::typedRightname() !== true) { exit(1); }
        if (PluginTicketrulesCompat::glpiMajor() !== 11) { exit(2); }
        if (PluginTicketrulesCompat::assetPath("css/ticketrules.css") !== "css/ticketrules.css") { exit(3); }
        if (PluginTicketrulesCompat::webPath(false) !== "/plugins/ticketrules") { exit(4); }
        if (PluginTicketrulesCompat::webPath(true) !== "https://glpi.local/plugins/ticketrules") { exit(5); }
        if (Plugin::$webDirCalled !== false) { exit(6); }
        PluginTicketrulesCompat::denyAccess();
        if (Html::$rightErrorDisplayed !== true) { exit(7); }
        exit(0);
        '
    );

    // 3) GLPI 12 (Html::displayRightError ausente -> denyAccess lanca AccessDeniedHttpException)
    runIsolatedPhpAssertion(
        [
            'TICKETRULES_TEST_TYPED_RIGHTNAME' => '1',
            'TICKETRULES_TEST_GLPI_VERSION'    => '12.0.0',
        ],
        '
        require "tests/bootstrap.php";
        if (PluginTicketrulesCompat::typedRightname() !== true) { exit(1); }
        $ref = new ReflectionProperty("PluginTicketrulesConfig", "rightname");
        if (!$ref->hasType()) { exit(2); }
        if (PluginTicketrulesConfig::$rightname !== "config") { exit(3); }
        if (PluginTicketrulesCompat::glpiMajor() !== 12) { exit(4); }
        if (PluginTicketrulesCompat::assetPath("css/ticketrules.css") !== "css/ticketrules.css") { exit(5); }
        if ($GLOBALS["PLUGIN_HOOKS"]["add_css"]["ticketrules"][0] !== "css/ticketrules.css") { exit(6); }
        if (PluginTicketrulesCompat::webPath(false) !== "/plugins/ticketrules") { exit(7); }
        if (PluginTicketrulesCompat::webPath(true) !== "https://glpi.local/plugins/ticketrules") { exit(8); }
        if (method_exists("Html", "displayRightError")) { exit(9); }
        $denied = false;
        try { PluginTicketrulesCompat::denyAccess(); } catch (\Glpi\Exception\Http\AccessDeniedHttpException $e) { $denied = true; }
        if (!$denied) { exit(10); }
        exit(0);
        '
    );

    // 4) POST fail-closed em front/config.form.php com entities_id invalido ou sem permissao
    runIsolatedPhpAssertion(
        [
            'TICKETRULES_TEST_TYPED_RIGHTNAME' => '1',
            'TICKETRULES_TEST_GLPI_VERSION'    => '11.0.0',
        ],
        '
        require "tests/bootstrap.php";
        plugin_ticketrules_install();
        $_SERVER = ["REQUEST_METHOD" => "POST", "HTTP_SEC_FETCH_SITE" => "same-origin"];
        $_POST   = ["entities_id" => "-5", "is_active" => "1"];
        include "ticketrules/front/config.form.php";
        if (Html::$rightErrorDisplayed !== true) { exit(1); }
        if (PluginTicketrulesConfig::getOwnConfig(0) !== null) { exit(2); }
        Html::$rightErrorDisplayed = false;
        Session::$accessibleEntities = [0 => true];
        $_POST = ["entities_id" => "2", "is_active" => "1"];
        include "ticketrules/front/config.form.php";
        if (Html::$rightErrorDisplayed !== true) { exit(3); }
        if (PluginTicketrulesConfig::getOwnConfig(2) !== null) { exit(4); }
        exit(0);
        '
    );

    // 5) Verificacoes de origem POST (requireSafePost) e desinstalacao limpa
    resetEnvironment();
    $_SERVER = ['REQUEST_METHOD' => 'GET'];
    assertSame(false, PluginTicketrulesCompat::requireSafePost(), 'rejeita GET em requireSafePost');

    $_SERVER = ['REQUEST_METHOD' => 'POST', 'HTTP_SEC_FETCH_SITE' => 'cross-site'];
    assertSame(false, PluginTicketrulesCompat::requireSafePost(), 'rejeita Sec-Fetch-Site cross-site');

    $_SERVER = ['REQUEST_METHOD' => 'POST', 'HTTP_SEC_FETCH_SITE' => 'same-origin'];
    assertSame(true, PluginTicketrulesCompat::requireSafePost(), 'aceita Sec-Fetch-Site same-origin');

    $_SERVER = ['REQUEST_METHOD' => 'POST', 'HTTP_ORIGIN' => 'null', 'HTTP_HOST' => 'glpi.local'];
    assertSame(false, PluginTicketrulesCompat::requireSafePost(), 'rejeita Origin null');

    $_SERVER = ['REQUEST_METHOD' => 'POST', 'HTTP_ORIGIN' => 'http://evil.example', 'HTTP_HOST' => 'glpi.local'];
    assertSame(false, PluginTicketrulesCompat::requireSafePost(), 'rejeita Origin divergente do Host');

    $_SERVER = ['REQUEST_METHOD' => 'POST', 'HTTP_ORIGIN' => 'http://glpi.local:8080', 'HTTP_HOST' => 'glpi.local'];
    assertSame(false, PluginTicketrulesCompat::requireSafePost(), 'rejeita Origin com porta divergente');

    $_SERVER = ['REQUEST_METHOD' => 'POST', 'HTTP_ORIGIN' => 'http://glpi.local', 'HTTP_HOST' => 'glpi.local'];
    assertSame(true, PluginTicketrulesCompat::requireSafePost(), 'aceita Origin HTTP igual ao Host');

    $_SERVER = ['REQUEST_METHOD' => 'POST', 'HTTP_HOST' => 'glpi.local'];
    assertSame(true, PluginTicketrulesCompat::requireSafePost(), 'permite POST quando proxy remove cabecalhos de origem');

    global $DB;
    assertTrue($DB->tableExists(PluginTicketrulesConfig::TABLE), 'tabela existe apos install');
    plugin_ticketrules_uninstall();
    assertSame(false, $DB->tableExists(PluginTicketrulesConfig::TABLE), 'tabela removida apos uninstall');
}

$tests = [
    'test_visible_length_strips_html_and_entities',
    'test_blocks_solution_when_missing_category_or_short_text',
    'test_allows_requester_approval_solved_to_closed',
    'test_blocks_direct_status_solve_without_valid_solution_or_task',
    'test_skips_cli_and_exempt_profiles',
    'test_entity_recursive_inheritance_and_child_override',
    'test_typed_and_untyped_rightname_compat',
];

$passed = 0;
foreach ($tests as $testFn) {
    $testFn();
    $passed++;
    echo "[OK] {$testFn}\n";
}

echo sprintf("Todos os %d testes passaram com sucesso.\n", $passed);
