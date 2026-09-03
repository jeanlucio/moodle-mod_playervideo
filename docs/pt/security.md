# 🔐 Segurança e Conformidade

* Controle de acesso baseado em capability: `mod/playervideo:view`, `mod/playervideo:attempt`,
  `mod/playervideo:manage`, `mod/playervideo:reviewresponses` e `mod/playervideo:viewreports`
  controlam cada ação e tela por papel.
* **"Blind JSON":** o cliente nunca recebe qual é a resposta certa antes de a resposta ser
  enviada — todo caminho de leitura que chega ao estudante (preview de pergunta, opções de
  enquete) descarta os campos de correção já no nível do SQL, em vez de filtrar um objeto já
  carregado, então o console do navegador nunca consegue recuperar o gabarito da forma que
  consegue no `window.H5PIntegration` do H5P Interactive Video.
* Toda Web Service reamarra o id que recebe (`attemptid`, `interactionid`, `responseid`,
  `playervideoid`) ao próprio contexto de módulo do curso e chama `validate_context()` antes de
  qualquer checagem de capability — nunca por chave primária isolada — verificado por uma suíte
  dedicada de isolamento entre instâncias (`tests/cross_instance_security_test.php`) em Web
  Services representativas de cada camada de capability.
* Uma pergunta puxada pra timeline é revalidada no servidor contra a mesma regra
  `moodle/question:useall`/`usemine` de categoria que a busca "puxar do banco" já aplica ao que
  **oferece** — fechando a brecha que uma chamada direta de Web Service poderia usar pra
  contornar essa busca e referenciar uma pergunta de uma categoria fora do curso do chamador.
* O plugin referencia o Banco de Questões diretamente em vez de usar a Question Usage API
  completa — uma simplificação deliberada, já comprovada em produção por um plugin irmão deste
  mesmo ecossistema, e de fato a escolha mais segura pro "Blind JSON" aqui: a leitura em tempo
  de execução nunca sequer seleciona a coluna de correção, em vez de carregar um objeto de
  pergunta completo e depender de filtragem cuidadosa em todo lugar que ele é exibido. O único
  lugar onde essa escolha pesa é no backup/restore, onde o plugin remapeia `questionid`/
  `answerid` à mão na restauração (pelos mesmos namespaces `question_created`/`question_answer`
  do próprio core, os mesmos que todo `qtype_*` usa), degradando graciosamente pro id original
  quando uma pergunta não fez parte do escopo do backup, ou descartando a interação por completo
  quando nem isso resolve.
* Regras de fluxo de trabalho (uma resposta já corrigida, uma interação que não é questão
  aberta, uma nota inválida) lançam um `moodle_exception` traduzido, nunca um
  `coding_exception` — são desfechos de regra de negócio que uma ação normal de usuário pode
  disparar, não erros de programador.
* Web Services são consumidas via `core/ajax`, cujo transporte já inclui e valida o sesskey
  automaticamente.
* As funcionalidades assistidas por IA nunca chamam um provedor externo diretamente: a geração
  passa pelo [`local_aihub`](https://github.com/jeanlucio/moodle-local_aihub) (BYOK, cujo
  próprio Privacy Provider já declara os provedores que alcança) ou pelo subsistema `core_ai`
  do Moodle — este plugin não precisa de nenhum `add_external_location_link()` próprio.
  Conteúdo gerado por IA (texto de pergunta, resumos em leitura fácil, comentário de correção) é
  tratado como entrada não confiável: renderizado com `format_text()`/escapado, nunca confiado
  ou inserido como HTML cru.
* Texto rico (notas, legendas, respostas, comentário de IA) é sempre renderizado via
  `format_text()` — nunca impresso cru.
* Compatível com a External API do Moodle.
* Privacy API totalmente implementada: dado pessoal (progresso de reprodução, tentativas,
  respostas) é exportável e apagável por usuário e por contexto; conteúdo autoral (a timeline,
  legendas, resumos em leitura fácil) é o próprio registro pedagógico do curso, não dado
  pessoal.
