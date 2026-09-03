# 📦 Requisitos

| Componente | Versão |
|------------|--------|
| Moodle     | 4.5 – 5.2 |
| PHP        | 8.1+   |

As funcionalidades assistidas por IA (geração de pergunta, resumo em leitura fácil, sugestão de
correção de questão aberta) funcionam sem nenhuma configuração e degradam graciosamente quando
nenhuma fonte de IA está disponível. Pra gerar conteúdo de fato, um dos dois precisa estar
acessível:

* [`local_aihub`](https://github.com/jeanlucio/moodle-local_aihub) instalado no mesmo site, com
  uma chave BYOK de site ou pessoal configurada pra pelo menos um provedor (Gemini, Groq,
  DeepSeek, ou qualquer endpoint compatível com OpenAI) — o caminho recomendado.
* O próprio subsistema `core_ai` do Moodle, configurado com um provedor institucional, como
  alternativa quando o `local_aihub` está ausente ou sem chave disponível.

O `block_playerhud` é uma integração inteiramente opcional — instale e configure num curso pra
habilitar concessão/cobrança de itens nesta atividade; nada aqui exige isso.
