# Painel do Operador para Issabel 5

Substituto do Painel do Operador (control_panel) do Issabel, no mesmo padrão visual do [Call Center Plus](https://github.com/yeahgns/issabel-callcenter-v2) e do [Relatório de ligações](https://github.com/yeahgns/issabel-cdr-report-v2). Mostra em tempo real quem está livre, quem está tocando, quem está em ligação e com quem, quem espera nas filas, e se os troncos estão respondendo.

Testado para Issabel 5, Asterisk 18, Rocky Linux 8, PHP 7.4. Sem dependências, sem framework.

## O que ele mostra

- **Ramais** sempre na mesma posição (ordem de número, nunca de estado), para a telefonista achar cada pessoa sem procurar. Cada ramal diz o estado, há quanto tempo, e com quem está falando ou quem está ligando, com o nome da fila quando a ligação veio por uma.
- **Tamanho automático**: com poucos ramais os cartões ficam grandes; com muitos, encolhem até caber na tela. Com centenas de ramais fica o tamanho menor (número, nome e estado) e a lista rola.
- **Ramal tocando pulsa**, e fica vermelho quando ninguém atende há mais de 20 segundos. É o único movimento da tela.
- **Filtros** por estado (livres, em ligação, tocando, offline), por grupo (por padrão, um grupo por fila) e busca por nome ou número.
- **Filas**: quem está esperando e há quanto tempo, quem atende (livre, ocupado, tocando, em pausa com o motivo) e os números do dia: atendidas, perdidas, porcentagem atendida no prazo e espera média.
- **Troncos**, embaixo dos ramais como no painel original: respondendo ou fora do ar, com latência e quantas ligações estão passando por cada um agora.
- **Estacionamento** (só as vagas ocupadas aparecem; as livres viram um resumo) e **conferências**.
- **Detalhes do ramal** num clique: IP, modelo do aparelho, latência, filas em que está.
- Tela cheia, para deixar numa TV da recepção.

## Instalação

O repositório é clonado direto na pasta de módulos do Issabel. O nome da pasta vira o nome do menu.

```bash
cd /var/www/html/modules
git clone https://github.com/yeahgns/issabel-painel-operador.git painel_operador
cd painel_operador
bash install.sh
```

O menu aparece no mesmo lugar do Painel do Operador original (normalmente **PBX > Painel do Operador**). Usuários que não são admin precisam de permissão no menu em System > User Management > Group Permission.

### Atualizar

```bash
cd /var/www/html/modules/painel_operador && git pull && bash install.sh
```

O `install.sh` não altera nenhum arquivo do repositório, então o `git pull` nunca dá conflito. Também não muda o dono dos arquivos: eles ficam com root, e o Apache só precisa ler.

### Remover

```bash
bash /var/www/html/modules/painel_operador/uninstall.sh   # --purge apaga também a configuração
rm -rf /var/www/html/modules/painel_operador
```

### Em várias VMs (Ansible)

O playbook `painel_operador.yml` (fica junto do seu `inventory.ini`, fora deste repositório) faz o equivalente a `git pull && bash install.sh` em todas as VMs de uma vez, a partir do `main`:

```bash
ansible-playbook -i inventory.ini painel_operador.yml --limit "@piloto.txt" --forks 20
```

Ele pula VMs que não são Issabel 5 e as que já estão atualizadas, volta sozinho para a versão anterior se o `install.sh` falhar, trabalha em lotes de 20 (para tudo se mais de 10% de um lote falhar) e grava o resultado de cada VM em `painel_relatorio.csv`.

## O que o instalador faz no sistema

- Confere a sintaxe dos arquivos PHP antes de qualquer coisa.
- Cria `/etc/httpd/conf.d/painel_operador.conf`, que bloqueia o acesso pelo navegador a `.git`, `lib/`, `setup/` e aos scripts, e testa se o bloqueio funcionou.
- Registra o menu com `issabel-menumerge`, embaixo do mesmo menu pai do `control_panel`.
- Cria `/etc/issabel/painel-operador.conf.php` na primeira vez (nunca sobrescreve).
- Roda `setup/selftest.php`, que lê o PABX e mostra quantos ramais, filas, troncos e ligações encontrou.

Não mexe no `control_panel` original. Os dois podem conviver.

## Configuração

Tudo opcional, em `/etc/issabel/painel-operador.conf.php`:

| Opção | Para que serve |
|---|---|
| `company` | Nome no topo do painel |
| `poll_seconds` | Intervalo de atualização (padrão 2) |
| `service_level_seconds` | Espera na fila que deixa a fila vermelha e conta para "no prazo" (padrão 20) |
| `long_ring_seconds` | Tempo tocando que deixa o ramal vermelho (padrão 20) |
| `hide_extensions` | Ramais que não aparecem no quadro, como `999` ou `2000` de teste |
| `queue_labels`, `trunk_labels` | Nome amigável por fila ou tronco |
| `groups` | Grupos próprios para o filtro de ramais, em vez de um por fila |
| `queue_log` | De onde vêm os números do dia por fila |
| `demo` | `true` mostra dados fictícios |

## Como funciona

A cada 2 segundos o navegador pede um JSON ao próprio módulo (`index.php?menu=painel_operador&action=snapshot&rawmode=yes`). Esse endpoint só responde para quem está logado no Issabel e tem permissão no menu.

O servidor monta o estado com:

- **banco asterisk**: quais ramais, filas, troncos e conferências existem, e os nomes;
- **AMI**, com uma conexão curta e `Events: off`: `CoreShowChannels`, `QueueStatus`, `PJSIPShowEndpoints`, `PJSIPShowContacts`, `SIPpeers`, `ExtensionStateList`, `DeviceStateList` (não perturbe), `ParkedCalls`, `Parkinglots`, `ConfbridgeListRooms` e `PJSIPShowRegistrationsOutbound`;
- `Getvar FROM_DID` só nas ligações que estão entrando, para saber por qual linha (tronco) elas vieram;
- **queue_log**, lido de trás para frente até a meia-noite, para os números do dia.

O resultado fica em cache por 1 segundo e é compartilhado por todos os navegadores abertos: dez telas abertas não viram dez conexões no AMI a cada ciclo. O painel antigo mantinha uma conexão AMI aberta por navegador, o tempo todo.

## Diagnóstico

```
index.php?menu=painel_operador&action=debug&rawmode=yes
```

Devolve o estado montado e também tudo que veio do AMI, ação por ação. Use quando algum ramal ou ligação aparecer diferente do que está acontecendo, de preferência com a ligação em andamento.

Pela linha de comando: `php /var/www/html/modules/painel_operador/setup/selftest.php`.

### Demonstração

```bash
php build/build-demo.php > painel-demo.html
```

Gera uma página única com o painel e uma simulação de operação (ligações entrando, tocando, sendo atendidas e esperando na fila).

## Estrutura

```
index.php                  ponte com o Issabel (_moduleContent)
lib/bootstrap.php          configuração, credenciais e conexões
lib/Ami.php                cliente AMI mínimo
lib/Snapshot.php           monta o estado (banco + AMI) e o cache compartilhado
lib/QueueStats.php         números do dia por fila a partir do queue_log
lib/view.php               HTML do painel
assets/                    painel.css, painel.js, painel-demo.js
setup/                     autoteste e configuração de exemplo
tests/                     AMI falso (Python) e teste do snapshot com SQLite
```

Para testar fora do Issabel: `python3 tests/mock_ami.py &` e depois `php tests/run_snapshot.php`.
