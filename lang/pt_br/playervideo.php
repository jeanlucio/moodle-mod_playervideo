<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Portuguese (Brazil) strings for PlayerVideo.
 *
 * @package    mod_playervideo
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['actions'] = 'Ações';
$string['addanswer'] = 'Adicionar alternativa';
$string['addhere'] = 'Adicionar aqui';
$string['addinteraction'] = 'Adicionar interação';
$string['addmarkerat'] = 'Adicionar marcação em {$a}';
$string['addpolloption'] = 'Adicionar opção';
$string['allmarkers'] = 'Todas as marcações';
$string['allowseekahead'] = 'Permitir avançar o vídeo livremente';
$string['attemptnumber'] = 'Tentativa {$a}';
$string['attemptsallowed'] = 'Tentativas permitidas';
$string['attemptsheader'] = 'Tentativas e reprodução';
$string['attemptsummaryheader'] = 'Resumo da tentativa';
$string['backtoactivity'] = 'Voltar para a atividade';
$string['blindmode'] = 'Modo texto-only';
$string['cannotattempt'] = 'Você não tem permissão para realizar esta atividade.';
$string['captioneditor'] = 'Editor de legenda';
$string['chooseattempttoreview'] = 'Escolha uma tentativa para revisar';
$string['completiondetail:allinteractions'] = 'Estudante precisa responder/ver todas as interações';
$string['completiondetail:watchtoend'] = 'Estudante precisa assistir até o fim';
$string['confirmanswer'] = 'Confirmar resposta';
$string['confirmdeleteinteraction'] = 'Excluir esta interação? Essa ação não pode ser desfeita.';
$string['continuewatching'] = 'Continuar';
$string['correctanswer'] = 'Resposta correta';
$string['correctionpending'] = 'Correção pendente';
$string['createandadd'] = 'Criar e adicionar';
$string['createhere'] = 'Criar aqui';
$string['disummary'] = 'Resumo em leitura fácil';
$string['disummary_pending'] = 'Resumo pendente de revisão do professor';
$string['editmarkerat'] = 'Editar marcação em {$a}';
$string['error_attemptnotinprogress'] = 'Esta tentativa não está mais em andamento.';
$string['error_hud_cost_qty'] = 'Informe uma quantidade de pelo menos 1';
$string['error_insufficienthuditems'] = 'Itens insuficientes do PlayerHUD para iniciar uma nova tentativa.';
$string['error_interactionalreadyanswered'] = 'Esta interação já foi respondida nesta tentativa.';
$string['error_interactionhasresponses'] = 'Esta interação já tem respostas de estudantes e não pode ser excluída.';
$string['error_interactionnotfound'] = 'Interação não encontrada.';
$string['error_invalidanswer'] = 'Resposta inválida.';
$string['error_invalidinteractiontype'] = 'Tipo de interação inválido.';
$string['error_invalidpolloption'] = 'Opção de enquete inválida.';
$string['error_invalidpolloptioncount'] = 'Uma enquete precisa de 2 a 6 opções.';
$string['error_invalidqtype'] = 'Tipo de pergunta inválido.';
$string['error_invalidsegments'] = 'Dados inválidos dos trechos assistidos.';
$string['error_invalidtrim'] = 'Janela de corte inválida.';
$string['error_noattemptsleft'] = 'Não há mais tentativas disponíveis para esta atividade.';
$string['error_nocorrectanswer'] = 'Marque pelo menos uma alternativa como correta.';
$string['error_noembed'] = 'Não foi possível identificar a fonte deste vídeo — confira a URL nas configurações da atividade.';
$string['error_noquestionselected'] = 'Escolha uma pergunta do banco ou crie uma primeiro.';
$string['error_notenoughanswers'] = 'Informe pelo menos duas alternativas.';
$string['error_notetextrequired'] = 'Informe o texto da nota.';
$string['error_notyourattempt'] = 'Esta tentativa não pertence a você.';
$string['error_onlyonecorrectanswer'] = 'Apenas uma alternativa pode ser correta numa pergunta de resposta única.';
$string['error_pollhasvotes'] = 'Esta enquete já tem votos; suas opções não podem mais ser alteradas.';
$string['error_questionnotfound'] = 'Pergunta não encontrada.';
$string['error_questiontextrequired'] = 'Informe o texto da pergunta.';
$string['error_responsetextrequired'] = 'Digite sua resposta.';
$string['error_seekaheadblocked'] = 'Não é possível avançar além do que já foi assistido.';
$string['error_timestamprequired'] = 'Informe o timestamp do vídeo antes.';
$string['error_videourl'] = 'Informe uma URL válida do YouTube/Vimeo';
$string['false'] = 'Falso';
$string['finishattempt'] = 'Finalizar tentativa agora';
$string['fixinline'] = 'Fixar na página do curso';
$string['grademethod'] = 'Método de avaliação';
$string['grademethod_average'] = 'Média das notas';
$string['grademethod_first'] = 'Primeira tentativa';
$string['grademethod_highest'] = 'Maior nota';
$string['grademethod_last'] = 'Última tentativa';
$string['hud_header'] = 'Integração com o PlayerHUD';
$string['hud_item_deleted'] = 'Item excluído (reconfigure este campo)';
$string['hud_item_disabled'] = '{$a} (desativado)';
$string['hud_noitem'] = 'Nenhum';
$string['hud_notincourse'] = 'A integração com o PlayerHUD aparecerá aqui assim que o bloco PlayerHUD for adicionado a este curso.';
$string['hud_notinstalled_desc'] = 'O plugin block_playerhud não está instalado neste site. Instale-o e depois adicione o bloco PlayerHUD a um curso para permitir que os professores recompensem estudantes com itens por respostas corretas.';
$string['hud_notinstalled_heading'] = 'Integração com o PlayerHUD';
$string['hud_outdated_desc'] = 'O plugin block_playerhud está instalado, mas numa versão anterior à v1.7.1, exigida por esta integração. Atualize o block_playerhud para permitir que os professores recompensem estudantes com itens por respostas corretas.';
$string['hud_outdated_heading'] = 'Integração com o PlayerHUD';
$string['hudcorrectitem'] = 'Item do PlayerHUD por resposta correta';
$string['hudretrycostitem'] = 'Item do PlayerHUD cobrado na retentativa';
$string['hudretrycostqty'] = 'Quantidade cobrada na retentativa';
$string['interactions'] = 'Interações';
$string['interactiontype'] = 'Tipo';
$string['interactionweight'] = 'Peso';
$string['introbody'] = 'Este vídeo pausa nos pontos que o professor marcou para exibir uma pergunta ou uma nota. Responda ou leia, e o vídeo continua. Dependendo de como o professor configurou esta atividade, pode ser possível tentar de novo.';
$string['introtitle'] = 'Como esta atividade funciona';
$string['manageinteractions'] = 'Gerenciar interações';
$string['maxattempts'] = 'Máximo de tentativas';
$string['maxattempts_unlimited'] = 'Sem limite';
$string['modulename'] = 'PlayerVideo';
$string['modulename_help'] = 'A atividade PlayerVideo reproduz um vídeo (YouTube, Vimeo ou um arquivo enviado) e pausa em pontos marcados para exibir uma pergunta ou uma nota, com correção automática de perguntas de múltipla escolha, correção assistida por IA de perguntas abertas, e acompanhamento do progresso de reprodução.';
$string['modulenameplural'] = 'PlayerVideos';
$string['newattempt'] = 'Nova tentativa';
$string['nointeractions'] = 'Nenhuma interação ainda.';
$string['notedescription'] = 'Um texto que pausa o vídeo — sem resposta certa ou errada.';
$string['notetext'] = 'Texto da nota';
$string['pause'] = 'Pausar';
$string['pendingcorrectionnotice'] = 'Suas respostas de questões abertas estão pendentes de revisão do professor; a nota desta tentativa aparecerá assim que forem corrigidas.';
$string['play'] = 'Reproduzir';
$string['playervideo:addinstance'] = 'Adicionar uma nova atividade PlayerVideo';
$string['playervideo:attempt'] = 'Realizar uma atividade PlayerVideo';
$string['playervideo:manage'] = 'Gerenciar interações, legendas e perguntas';
$string['playervideo:reviewresponses'] = 'Revisar respostas de questões abertas';
$string['playervideo:view'] = 'Visualizar uma atividade PlayerVideo';
$string['playervideo:viewreports'] = 'Visualizar relatórios do PlayerVideo';
$string['pluginadministration'] = 'Administração do PlayerVideo';
$string['pluginname'] = 'PlayerVideo';
$string['polldescription'] = 'Estudantes escolhem uma opção e veem como a turma votou.';
$string['pollprompt'] = 'Pergunta da enquete';
$string['preview'] = 'Prévia';
$string['pullfrombank'] = 'Puxar do banco';
$string['qtypemultichoice'] = 'Múltipla escolha';
$string['qtypetruefalse'] = 'Verdadeiro/Falso';
$string['questioncreatedandadded'] = 'Pergunta criada e adicionada na timeline.';
$string['questiondescription'] = 'Puxe uma pergunta do banco ou crie uma aqui.';
$string['questionsettings'] = 'Configurações de pergunta';
$string['questiontext'] = 'Texto da pergunta';
$string['questiontype'] = 'Tipo de pergunta';
$string['reportheader'] = 'Analytics';
$string['result_correct'] = 'Correto';
$string['result_incorrect'] = 'Incorreto';
$string['result_notreached'] = 'Não alcançado';
$string['result_pending'] = 'Correção pendente';
$string['result_viewed'] = 'Visto';
$string['reviewattempt'] = 'Revisar tentativa';
$string['reviewingattempt'] = 'Revisando a tentativa {$a}';
$string['reviewpreviousattempts'] = 'Revisar uma tentativa anterior';
$string['searchquestions'] = 'Buscar perguntas';
$string['selectedquestionhint'] = 'Selecionada — clique em Salvar abaixo para adicionar na timeline.';
$string['singleanswer'] = 'Resposta única';
$string['startattempt'] = 'Iniciar';
$string['timelinehint'] = 'Arraste as réguas cinzas para cortar a janela de reprodução; clique em qualquer outro ponto da barra para adicionar uma marcação ali.';
$string['timelinelabel'] = 'Linha do tempo do vídeo';
$string['timestamp'] = 'Timestamp (segundos)';
$string['transcriptmode'] = 'Alternar pro modo texto-only';
$string['trimend'] = 'Fim do vídeo (segundos)';
$string['trimheader'] = 'Janela de reprodução (corte)';
$string['trimsaved'] = 'Janela de corte salva.';
$string['trimstart'] = 'Início do vídeo (segundos)';
$string['true'] = 'Verdadeiro';
$string['typenote'] = 'Nota';
$string['typepoll'] = 'Enquete';
$string['typequestion'] = 'Pergunta';
$string['videofile'] = 'Arquivo de vídeo';
$string['videosource'] = 'Fonte do vídeo';
$string['videotype_html5'] = 'Upload';
$string['videotype_vimeo'] = 'Vimeo';
$string['videotype_youtube'] = 'YouTube';
$string['videourl'] = 'URL do vídeo';
$string['yourgrade'] = 'Sua nota: {$a}';
$string['yourresponse'] = 'Sua resposta';
