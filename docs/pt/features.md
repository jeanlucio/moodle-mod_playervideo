# ✨ Funcionalidades

* 🎬 **Três Fontes de Vídeo, Um Só Player:** YouTube, Vimeo e vídeo HTML5 hospedado no próprio
  Moodle, todos atrás da mesma interface — os controles nativos do provedor ficam sempre
  desligados em favor de uma única timeline que professor e estudante usam, então trocar a
  fonte do vídeo nunca muda o comportamento da atividade.
* 🖱️ **Editor de Timeline por Clique:** O professor marca uma pergunta, uma nota ou uma enquete
  clicando diretamente no ponto certo da própria timeline do vídeo, arrasta duas alças na mesma
  timeline pra recortar a janela de reprodução (início/fim), e edita ou remove qualquer marcador
  na mesma tela.
* ❓ **Três Tipos de Interação:** *Pergunta* (múltipla escolha, verdadeiro/falso ou dissertativa,
  puxada do Banco de Questões real ou criada na hora), *Nota* (um aviso em texto inline, sem
  nota), e *Enquete* (sem resposta certa — o resultado agregado da turma aparece pro estudante
  logo depois de votar, nunca conta pra nota).
* 🤖 **Autoria de Perguntas Assistida por IA:** Gere uma pergunta por vez, ou cole a transcrição
  de uma aula e gere um lote inteiro de uma vez — toda pergunta gerada por IA cai numa tela de
  revisão pro professor aceitar ou descartar antes de virar uma interação real na timeline,
  nunca criada sem supervisão. O timestamp de uma pergunta gerada é sempre ancorado a um ponto
  real da transcrição, nunca calculado livremente pela IA.
* 💾 **Auto-Save, Retomada e Anti-Avanço:** Toda resposta e toda posição de reprodução são salvas
  automaticamente pela própria Web Service da interação, com uma fila de retentativa via
  `localStorage` que sobrevive a uma queda de conexão; recarregar a página retoma exatamente a
  mesma tentativa em andamento. Avançar além do que já foi de fato assistido é bloqueado no
  servidor (configurável por atividade); retroceder é sempre livre.
* 🔁 **Múltiplas Tentativas e Agregação de Nota:** Uma atividade pode permitir um número fixo de
  tentativas ou nenhum limite, agregando a nota final pela maior, média, primeira ou última
  tentativa.
* ⏳ **Nota Retida Até Toda Questão Aberta Ser Corrigida:** Uma tentativa com resposta
  dissertativa pendente nunca chega ao Diário de Notas com uma nota parcial — ela permanece
  `pendingcorrection` até que um professor (com apoio opcional de IA, veja abaixo) confirme a
  última.
* 🌐 **Legenda Manual, Mesclada com Faixas Nativas:** O professor pode escrever uma legenda
  (VTT, colada como "timestamp + texto" ou como um `.vtt` real) por idioma; o seletor de
  legenda do estudante mescla com qualquer legenda nativa que YouTube/Vimeo já exponham pra
  aquele vídeo, numa lista só.
* 📄 **Resumo em Leitura Fácil por IA, Sempre Revisado Antes:** Um resumo em linguagem simples
  do conteúdo do vídeo, gerado por IA — sempre pendente até um professor aprovar ou editar,
  exatamente como uma pergunta gerada; o estudante só vê a versão aprovada.
* 🦻 **Modo Texto-Only:** A mesma tentativa — mesma nota, mesmo progresso —
  renderizada como um único documento linear mesclando legendas e interações na ordem certa,
  pra um estudante que não pode ou não quer usar o player de vídeo. Sempre disponível na tela
  inicial da atividade, não escondido atrás de uma configuração de acessibilidade.
* 🧑‍🏫 **Correção de Questão Aberta Assistida por IA:** Gera uma sugestão de nota e comentário
  pra uma resposta, ou pra todas as pendentes de uma vez — o professor sempre confirma ou edita
  a nota final antes que ela valha; a IA só propõe um score de completude, escalado pro peso
  real da pergunta no servidor, nunca chamada a raciocinar sobre a escala de nota em si.
* 📊 **Analytics Integrado:** Uma página de relatório agrega resultados por pergunta (% de
  acerto pra múltipla escolha, contagem pendente/corrigida pra questão aberta) e por estudante
  (tentativas, nota final, percentual assistido, conclusão) — dividido em duas capabilities
  independentes, então um professor que só corrige respostas ou só vê analytics continua vendo
  exatamente a metade que lhe cabe.
* 🎮 **Integração Opcional com o PlayerHUD:** Concede ou cobra itens de inventário do
  `block_playerhud` numa resposta correta ou numa retentativa, quando o bloco está instalado e
  configurado no curso — inteiramente ausente caso contrário, nunca uma dependência obrigatória.
* 🔒 **"Blind JSON" por Construção:** O cliente nunca recebe qual é a resposta certa antes de o
  estudante enviar — a correção sempre acontece no servidor. É uma diferença estrutural e
  deliberada em relação ao H5P Interactive Video, cujo JSON de conteúdo inteiro (pergunta,
  alternativas e qual delas é a certa) fica embutido em `window.H5PIntegration` já na carga da
  página, legível pelo console do navegador antes mesmo de responder.
* 📦 **Backup e Restauração:** Suporte completo a backup/restore no formato Moodle 2, incluindo
  "Duplicar atividade" — a timeline, legendas e resumos em leitura fácil sempre acompanham a
  atividade; tentativas e respostas seguem a configuração "Incluir estudantes matriculados".
  Como o plugin referencia o Banco de Questões diretamente em vez de usar a Question Usage API
  completa, `questionid`/`answerid` são remapeados à mão na restauração, pelos mesmos
  namespaces `question_created`/`question_answer` que todo tipo de questão do core usa.
* 🔐 **API de Privacidade e Isolamento entre Instâncias:** Implementação completa da Privacy
  API, e uma suíte de teste dedicada provando que toda Web Service deriva seu contexto de
  acesso do próprio curso do recurso — nunca de um id que o chamador simplesmente conhece.
