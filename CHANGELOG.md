# Changelog

## [1.0.0] - 2026-11-16

### Adicionado
- Validação configurável por entidade com herança recursiva na árvore de entidades do GLPI.
- Suporte completo ao GLPI 10.0.x, 11.0.x e 12.0.x em pacote único (PHP 7.4+), com declaração condicional de `$rightname` via reflexão e caminhos de ativos compatíveis entre versões.
- Regras obrigatórias de chamado: categoria, tipo, técnico atribuído, grupo técnico, localização, origem da requisição e tarefa ou tempo trabalhado.
- Validação de tamanho mínimo visível na solução (desconsiderando tags HTML, entidades vazias e espaços repetidos) na criação/edição de `ITILSolution` e na transição direta de status do `Ticket`.
- Liberação automática para aprovação/recusa de solução pelo requerente (`Solucionado -> Fechado`), perfis isentos, tipos de chamado filtráveis e execuções CLI/não interativas.
- Tela administrativa em **Configurar › Plugins › Ticket Rules** (`front/config.form.php`) com verificação de permissão e validação de origem em requisições POST.
- Suíte de testes unitários standalone em `tests/` sem dependências externas.
