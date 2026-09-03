# 📖 Como Usar

## Criando a timeline

1. Adicione uma atividade **PlayerVideo** a um curso, apontando pra uma URL do YouTube/Vimeo ou
   um vídeo HTML5 enviado.
2. Abra **Gerenciar interações** (no menu de configurações da própria atividade). O vídeo toca
   com uma única timeline própria embaixo — clique em qualquer ponto da timeline pra ir até lá,
   ou use o botão **Adicionar aqui** pra abrir o seletor de marcador na posição atual.
3. Escolha um tipo de marcador:
   * **Pergunta** — puxe uma pergunta existente do Banco de Questões (múltipla escolha,
     verdadeiro/falso ou dissertativa), crie uma na hora, ou gere uma com IA a partir de um
     prompt curto.
   * **Nota** — um texto curto mostrado ao estudante quando o vídeo chega naquele ponto.
   * **Enquete** — um prompt com 2 a 6 opções; sem resposta certa, o resultado é mostrado à
     turma depois de votar.
4. Arraste as duas alças na timeline pra recortar a janela de reprodução da atividade
   (início/fim) — o estudante nunca vê nem consegue buscar fora dela.
5. Um marcador existente pode ser reaberto, editado ou excluído na mesma tela; um marcador que
   já tem resposta de estudante não pode ser excluído, só editado.

### Gerando perguntas com IA

* **Uma de cada vez:** na aba Pergunta do editor de marcador, descreva o que a pergunta deve
  cobrir e gere-a — ela cai como uma pergunta normal do Banco de Questões, pronta pra revisar
  antes de salvar.
* **Em lote, a partir de uma transcrição:** cole a transcrição de uma aula (com timestamps, "12:34
  ..." por linha ou similar), escolha quantas perguntas e de qual(is) tipo(s), e gere o lote
  inteiro de uma vez. Cada candidata aparece numa tela de revisão com seu timestamp ancorado —
  aceite as que valem a pena, descarte o resto. Nada é adicionado à timeline até você aceitar.
* Ao colar a transcrição, você também pode reusar o mesmo texto como a legenda da atividade
  naquele idioma, com confirmação antes de sobrescrever uma legenda já existente.

### Legendas e o resumo em leitura fácil

* Abra o editor de **Legendas** (no mesmo modal do editor de marcador) pra colar ou escrever uma
  legenda por idioma — texto simples em linhas "timestamp texto" ou o conteúdo de um `.vtt` real.
* Gere um resumo em leitura fácil por IA do conteúdo do vídeo, por idioma, no mesmo lugar; ele
  fica pendente até você revisar e aprovar (ou editar antes) — o estudante só vê um resumo já
  aprovado, mostrado a ele por um botão na tela inicial da atividade.

## Fazendo a atividade (visão do estudante)

1. A tela inicial mostra a introdução do vídeo, o botão do resumo em leitura fácil (se houver um
   aprovado) e um link pra trocar pro modo texto-only.
2. Reproduzir o vídeo pausa automaticamente em cada marcador: responda a pergunta, leia a nota,
   ou vote na enquete pra continuar. Avançar além do que já foi assistido é bloqueado (a menos
   que a atividade permita); retroceder é sempre livre.
3. Progresso e cada resposta são salvos automaticamente conforme você avança — fechar a aba e
   voltar retoma exatamente a mesma tentativa de onde parou.
4. Ao terminar, um resumo da tentativa é mostrado; se a atividade permitir mais de uma tentativa
   (e o limite ainda não foi atingido), uma nova pode ser iniciada. Uma tentativa concluída
   sempre pode ser reaberta em **modo revisão somente leitura**: cada interação mostra a resposta
   dada, a resposta correta, e o feedback por alternativa — nunca revelado enquanto a tentativa
   ainda estava em andamento.
5. O **modo texto-only** renderiza exatamente a mesma tentativa — mesma nota, mesmo progresso —
   como um único documento linear mesclando as legendas e interações na ordem de leitura, pra um
   estudante que não pode ou não quer usar o player de vídeo.

## Corrigindo questões abertas

1. Abra a página de **Relatório** da atividade — a fila de correção só aparece pra quem tem
   `mod/playervideo:reviewresponses`.
2. Cada resposta pendente mostra a pergunta, a resposta do estudante, e um botão **Gerar** pra
   uma sugestão de nota e comentário via IA — ou use **Gerar todas as sugestões** pra pedir uma
   pra cada resposta que ainda não tem, uma de cada vez (nunca em paralelo, pra respeitar o
   limite de taxa de um provedor de IA real).
3. Confirme a nota final e o comentário de cada resposta — editar a sugestão da IA antes é a
   mesma ação que aprová-la como está. A sugestão da IA é sempre só um score de completude de
   0.0 a 1.0; ela nunca grava a nota oficial nem sabe o valor em pontos da pergunta — essa
   escala acontece no servidor.
4. Assim que toda resposta pendente de uma tentativa é corrigida, a nota final da tentativa é
   calculada e enviada ao Diário de Notas automaticamente.

## Analytics

A mesma página de **Relatório** também mostra, pra quem tem `mod/playervideo:viewreports`:
estatísticas por pergunta (% de acerto pra múltipla escolha, quantas respostas ainda estão
pendentes vs. corrigidas pra questão aberta) e estatísticas por estudante (tentativas, nota
final, percentual do vídeo assistido, conclusão da atividade). Quem tem só uma das duas
capabilities vê só a sua própria metade da página.
