# Moodle Interactive Video PlayerVideo

![Moodle](https://img.shields.io/badge/Moodle-4.5%2B-orange?style=flat&logo=moodle&logoColor=white)
![License](https://img.shields.io/badge/License-GPLv3-blue?style=flat)
![Status](https://img.shields.io/badge/Status-Alpha-red?style=flat)
[![Latest Release](https://img.shields.io/github/v/release/jeanlucio/moodle-mod_playervideo?style=flat)](https://github.com/jeanlucio/moodle-mod_playervideo/releases)
[![Author](https://img.shields.io/badge/by-Jean_Lucio-6f42c1?style=flat)](https://github.com/jeanlucio/)

[![Moodle Plugin CI](https://github.com/jeanlucio/moodle-mod_playervideo/actions/workflows/ci.yml/badge.svg)](https://github.com/jeanlucio/moodle-mod_playervideo/actions/workflows/ci.yml)
[![Last Commit](https://img.shields.io/github/last-commit/jeanlucio/moodle-mod_playervideo?style=flat)](https://github.com/jeanlucio/moodle-mod_playervideo/commits)
[![Open Issues](https://img.shields.io/github/issues/jeanlucio/moodle-mod_playervideo?style=flat)](https://github.com/jeanlucio/moodle-mod_playervideo/issues)

[English](#english) | [Português](#português)

---

## English

**PlayerVideo** turns a YouTube, Vimeo or self-hosted video into an interactive Moodle
activity: teachers mark questions, notes and polls directly on the video's own timeline —
pulling questions from the real Question Bank or generating them with AI from a pasted
transcript — and students answer inline as the video plays, with auto-save, resume, anti-skip
and a full grading and analytics workflow.

📚 **[Full documentation](https://jeanlucio.github.io/moodle-mod_playervideo/)** — features,
installation, usage guide, the full test suite, and security details.

### 📦 Requirements

| Component | Version |
|-----------|---------|
| Moodle    | 4.5 – 5.2 |
| PHP       | 8.1+    |

### 🛠️ Installation & Configuration

1. Download the `.zip` file or clone this repository.
2. Extract the folder into your Moodle `mod/` directory.
3. Rename the folder to `playervideo` (if necessary).
   Final path:
   `your-moodle/mod/playervideo/`
4. Visit **Site administration > Notifications** to complete installation, then add a
   **PlayerVideo** activity to a course.

No manual capability assignment is needed for standard roles. AI-assisted features
(question generation, easy-read summaries, grading suggestions) are entirely optional and
degrade gracefully without a configured source, as covered in the
[Requirements](https://jeanlucio.github.io/moodle-mod_playervideo/#requirements) section of the
full documentation.

### 🆘 Support

Found a bug or have a question? Open an issue on the
[issue tracker](https://github.com/jeanlucio/moodle-mod_playervideo/issues).

### 📄 License

This project is licensed under the **GNU General Public License v3 (GPLv3)**.

**Copyright:** 2026 Jean Lúcio

### 👤 Maintainer

Maintained by [Jean Lúcio](https://github.com/jeanlucio).

[⬆️ Back to top](#english)

---

## Português

O **PlayerVideo** transforma um vídeo do YouTube, Vimeo ou hospedado no próprio Moodle numa
atividade interativa: o professor marca perguntas, notas e enquetes diretamente na própria
timeline do vídeo — puxando perguntas do Banco de Questões real ou gerando com IA a partir de
uma transcrição colada — e o estudante responde inline enquanto o vídeo toca, com auto-save,
retomada, anti-avanço e um fluxo completo de correção e analytics.

📚 **[Documentação completa](https://jeanlucio.github.io/moodle-mod_playervideo/pt.html)** —
funcionalidades, instalação, guia de uso, a suíte completa de testes, e detalhes de segurança.

### 📦 Requisitos

| Componente | Versão |
|------------|--------|
| Moodle     | 4.5 – 5.2 |
| PHP        | 8.1+   |

### 🛠️ Instalação e Configuração

1. Baixe o arquivo `.zip` ou clone este repositório.
2. Extraia na pasta `mod/` do seu Moodle.
3. Renomeie para `playervideo` (se necessário).
   Caminho final:
   `seu-moodle/mod/playervideo/`
4. Acesse **Administração do site > Notificações** para concluir a instalação, depois adicione
   uma atividade **PlayerVideo** a um curso.

Nenhuma atribuição manual de capability é necessária pros papéis padrão. As funcionalidades
assistidas por IA (geração de pergunta, resumos em leitura fácil, sugestões de correção) são
inteiramente opcionais e degradam graciosamente sem uma fonte configurada, conforme explicado
na seção [Requisitos](https://jeanlucio.github.io/moodle-mod_playervideo/pt.html#requirements)
da documentação completa.

### 🆘 Suporte

Encontrou um bug ou tem alguma dúvida? Abra uma issue no
[rastreador de issues](https://github.com/jeanlucio/moodle-mod_playervideo/issues).

### 📄 Licença

Este projeto é licenciado sob a **GNU General Public License v3 (GPLv3)**.

**Copyright:** 2026 Jean Lúcio

### 👤 Mantenedor

Mantido por [Jean Lúcio](https://github.com/jeanlucio).

[⬆️ Voltar ao topo](#português)
