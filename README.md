# Ticket Rules

**Regras obrigatórias de qualidade na solução e no encerramento de chamados no GLPI.**

O **Ticket Rules** impede que chamados sejam solucionados ou fechados sem classificação completa, técnico/grupo atribuído, registro de tarefa ou tempo trabalhado e descrição de solução com tamanho mínimo real.

| Versão do GLPI | Suporte |
|---|---|
| **12.0.x** | ✅ |
| **11.0.x** | ✅ |
| **10.0.x** | ✅ |

PHP **7.4** ou superior.

---

## Funcionalidades

- **Configuração por entidade com herança recursiva:**
  - defina regras na entidade raiz ou em entidades específicas;
  - entidades filhas sem regra própria herdam automaticamente do ancestral mais próximo marcado como recursivo;
  - entidades sem regra ativa na hierarquia permanecem liberadas por padrão.
- **Campos obrigatórios configuráveis:**
  - **Categoria** (`itilcategories_id`);
  - **Tipo do chamado** (`type`);
  - **Técnico atribuído** (`glpi_tickets_users` com tipo atribuído);
  - **Grupo técnico atribuído** (`glpi_groups_tickets` com tipo atribuído);
  - **Localização** (`locations_id`);
  - **Origem da requisição** (`requesttypes_id`);
  - **Tarefa ou tempo trabalhado** (`actiontime > 0` ou existência de `TicketTask`).
- **Qualidade mínima da solução:**
  - exige número mínimo de **caracteres visíveis**;
  - remove tags HTML, decodifica entidades (como `&nbsp;`, `&lt;`, `&amp;`) e colapsa espaços múltiplos antes de contar — soluções vazias com formatação HTML não burlam a regra.
- **Controle fino de fluxo:**
  - escolha se a validação ocorre **ao solucionar**, **ao fechar**, ou em ambos;
  - filtre por **tipo de chamado** (**Incidente** e/ou **Requisição**);
  - defina **perfis isentos** (ex.: Super-Admin para ajustes operacionais);
  - **nunca bloqueia** aprovações ou recusas de solução feitas pelo requerente (inclusive transição `Solucionado -> Fechado` na interface simplificada ou via aprovação da solução);
  - **ignora automaticamente** execuções em CLI (`bin/console`) e tarefas sem usuário interativo (cron, coletor de e-mail).

---

## Instalação

### Pelo terminal (Linux)

```bash
GLPI_DIR=/var/www/glpi

# Baixe o pacote da Release e extraia na pasta plugins
cd /tmp
unzip -q ticketrules-1.0.0.zip -d "$GLPI_DIR/plugins/"
sudo chown -R www-data:www-data "$GLPI_DIR/plugins/ticketrules"

# Instale, ative e limpe o cache
cd "$GLPI_DIR"
sudo -u www-data php bin/console plugin:install --username=glpi ticketrules
sudo -u www-data php bin/console plugin:activate ticketrules
sudo -u www-data php bin/console cache:clear
```

### Pela interface web

1. Baixe o arquivo `ticketrules-<versão>.zip` na aba [Releases](https://github.com/GustavoMS0/GLPI-TicketRules/releases).
2. Extraia o conteúdo em `plugins/` do GLPI, resultando em `plugins/ticketrules/setup.php`.
3. Acesse **Configurar › Plugins**, clique em **Instalar** e depois em **Ativar** no **Ticket Rules**.

---

## Configuração

1. Abra **Configurar › Plugins › Ticket Rules** (ícone de engrenagem / configuração do plugin).
2. Selecione a **Entidade** desejada no topo da tela.
3. Marque **Ativar regras nesta entidade** e ajuste:
   - **Aplicar recursivamente às entidades filhas**;
   - Momento da validação (**ao solucionar** / **ao fechar**) e **tipos de chamado**;
   - Campos obrigatórios do chamado;
   - **Tamanho mínimo da solução** em caracteres visíveis;
   - **Perfis isentos**.
4. Clique em **Salvar regras**. Caso queira que uma entidade filha volte a herdar as regras da entidade superior, clique em **Remover regra desta entidade**.

---

## Atualização e desinstalação

- **Atualização:** substitua a pasta `plugins/ticketrules` pelos arquivos da nova versão e execute `plugin:install` seguido de `cache:clear`. As configurações gravadas em `glpi_plugin_ticketrules_configs` são preservadas.
- **Desinstalação:** ao desinstalar o plugin em **Configurar › Plugins** (ou via `php bin/console plugin:uninstall ticketrules`), a tabela `glpi_plugin_ticketrules_configs` é removida de forma limpa, sem deixar tabelas órfãs no banco de dados.

---

## Licença

Distribuído sob a licença **GNU General Public License v3.0 ou posterior (GPLv3+)**. Veja o arquivo [LICENSE](LICENSE).

Copyright (C) 2026 by G. Martins.
