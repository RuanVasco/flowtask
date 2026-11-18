# Flowtask

Workflows inside GLPI tickets. Each step becomes a ticket task or validation assigned to the right actor, and the next step starts automatically when the previous one is done.

> Status: early development (v0.1.0 in progress). Not ready for production.

[Português](#português) | [English](#english)

## Português

O Flowtask executa workflows dentro dos chamados do GLPI:

- etapas atribuídas por ator (usuário, grupo, gestor do requerente);
- aprovações usando a validação nativa do GLPI;
- transições condicionais entre etapas;
- linha do tempo do fluxo dentro do chamado;
- versionamento dos fluxos (instâncias em andamento continuam na versão em que começaram).

### Requisitos

- GLPI 12.0.x
- PHP 8.2+

### Instalação

1. Baixe a última release e extraia em `plugins/flowtask` (ou `marketplace/flowtask`).
2. Em **Configurar > Plugins**, instale e ative o Flowtask.

A partir de um clone do repositório, rode `npm install` na pasta do plugin (Node.js 20+) para gerar o diagrama dos fluxos; o arquivo da release já o traz pronto.

### Desenvolvimento

Veja [CONTRIBUTING.md](CONTRIBUTING.md).

## English

Flowtask runs workflows inside GLPI tickets:

- steps assigned per actor (user, group, requester manager);
- approvals using GLPI native validations;
- conditional transitions between steps;
- workflow timeline inside the ticket;
- workflow versioning (running instances keep the version they started with).

### Requirements

- GLPI 12.0.x
- PHP 8.2+

### Installation

1. Download the latest release and extract it into `plugins/flowtask` (or `marketplace/flowtask`).
2. In **Setup > Plugins**, install and enable Flowtask.

From a git checkout, run `npm install` in the plugin directory (Node.js 20+) to build the workflow diagram; the release archive ships it built.

### Development

See [CONTRIBUTING.md](CONTRIBUTING.md).

## License

[GPL-3.0-or-later](LICENSE)
