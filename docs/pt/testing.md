# 🧪 Testes Automatizados

O PlayerVideo tem uma suíte de testes PHPUnit cobrindo os serviços de domínio, as 22 Web
Services, as regras de conclusão, a renderização de saída, a limpeza de desinstalação, a
conformidade com a Privacy API, backup/restore e isolamento entre instâncias. Todo push no CI
roda contra a matriz completa (Moodle 4.5 → 5.2, PHP 8.2 → 8.4, PostgreSQL e MariaDB), mais
validação ao vivo de ponta a ponta contra provedores de IA reais (Gemini/Groq/DeepSeek, via
`local_aihub`) pra cada funcionalidade assistida por IA.

### Testes de Web Services (`tests/external/`)

| Arquivo de teste | Casos |
|-------------------|------:|
| `save_interaction_test.php` | 15 |
| `submit_answer_test.php` | 8 |
| `generate_questions_batch_test.php` | 8 |
| `save_caption_test.php` | 7 |
| `create_question_test.php` | 7 |
| `start_attempt_test.php` | 6 |
| `generate_response_correction_test.php` | 6 |
| `generate_question_ai_test.php` | 6 |
| `save_progress_test.php` | 5 |
| `save_trim_test.php` | 4 |
| `save_di_summary_test.php` | 4 |
| `review_response_test.php` | 4 |
| `get_poll_results_test.php` | 4 |
| `get_attempt_review_test.php` | 4 |
| `generate_di_summary_test.php` | 4 |
| `finish_attempt_test.php` | 4 |
| `get_report_test.php` | 3 |
| `get_interactions_test.php` | 3 |
| `get_captions_test.php` | 3 |
| `search_questions_test.php` | 2 |
| `get_pending_corrections_test.php` | 2 |
| `get_di_summaries_test.php` | 2 |
| **Subtotal** | **111** |

### Testes de Serviços de Domínio (`tests/local/`)

| Arquivo de teste | Casos |
|-------------------|------:|
| `question_service_test.php` | 19 |
| `attempt_manager_test.php` | 16 |
| `hud_service_test.php` | 16 |
| `video_source_test.php` | 9 |
| `caption_service_test.php` | 7 |
| `intro_service_test.php` | 4 |
| `di_summary_service_test.php` | 3 |
| `transcript_service_test.php` | 3 |
| `ai_service_test.php` | 2 |
| **Subtotal** | **79** |

### Testes de Biblioteca, Conclusão, Saída, Privacidade, Backup e Isolamento

| Arquivo de teste | Casos |
|-------------------|------:|
| `lib_test.php` | 15 |
| `privacy/provider_test.php` | 14 |
| `mod_form_test.php` | 17 |
| `backup_restore_test.php` | 9 |
| `cross_instance_security_test.php` | 6 |
| `completion/custom_completion_test.php` | 4 |
| `uninstall_test.php` | 2 |
| `output/view_render_test.php` | 1 |
| **Subtotal** | **68** |

| **Total Geral** | **258** |

```bash
vendor/bin/phpunit --bootstrap lib/phpunit/bootstrap.php mod/playervideo
```

**Cobertura de linhas por classe (PHPUnit + Xdebug, via a ferramenta `moodle-coverage`):**

| Classe | Cobertura de linhas |
|--------|:--------------------:|
| `local\di_summary_service` | 100% |
| `local\intro_service` | 100% |
| `local\video_source` | 100% |
| `external\get_attempt_review` | 100% |
| `external\get_captions` | 100% |
| `external\get_di_summaries` | 100% |
| `external\get_interactions` | 100% |
| `external\get_poll_results` | 100% |
| `external\save_caption` | 100% |
| `external\save_trim` | 100% |
| `local\attempt_manager` | 98% |
| `external\create_question` | 98% |
| `external\save_progress` | 98% |
| `external\review_response` | 98% |
| `external\search_questions` | 98% |
| `external\get_pending_corrections` | 98% |
| `external\finish_attempt` | 97% |
| `external\save_di_summary` | 97% |
| `external\save_interaction` | 97% |
| `external\get_report` | 96% |
| `privacy\provider` | 94% |
| `local\hud_service` | 89% |
| `external\submit_answer` | 85% |
| `external\start_attempt` | 80% |
| `local\transcript_service` | 78% |
| `completion\custom_completion` | 77% |
| `local\question_service` | 72% |
| `local\caption_service` | 67% |
| `external\generate_di_summary` | 62% |
| `external\generate_questions_batch` | 52% |
| `external\generate_question_ai` | 52% |
| `external\generate_response_correction` | 49% |
| `local\ai_service` | 32% |
| **Geral** | **81%** |

**Arquivos legados (sem namespace), medidos à parte:** `mod_form.php` e as duas classes de
`backup/moodle2/*_stepslib.php` nunca são autoloaded sob o namespace `mod_playervideo\`, então a
tabela acima — restrita a `classes/` — nunca os enxerga. Medindo a árvore inteira do plugin
(`moodle-coverage mod/playervideo --filter .`), eles aparecem sozinhos:

| Arquivo / classe | Cobertura de linhas |
|-------------------|:--------------------:|
| `backup_playervideo_activity_structure_step` | 100% |
| `mod_form.php` (`mod_playervideo_mod_form`) | 100% |
| `restore_playervideo_activity_structure_step` | 95% |

Os dois fixes de backup/restore da Fase 9 (anotação de `videofile`/`posterimage`) são
inteiramente exercitados pelo round trip real de `backup_and_restore_into_new_course()` do
`backup_restore_test.php` — o único método da etapa de backup está 100% coberto. `mod_form.php`
tinha uma lacuna real e fechável, achada por essa varredura: `data_preprocessing()` (o método que
corrigiu o bug de perda silenciosa de dado do `videofile`/`posterimage`, ver a página de
funcionalidades) começou com **0% de cobertura de método** — provado correto só por validação ao
vivo via Playwright, nunca por um teste unitário direto. Uma segunda rodada fechou todo o resto
do arquivo (11 testes novos: os seletores de recompensa do HUD só aparecendo quando o
`block_playerhud` está configurado no curso, um id de item do HUD "obsoleto" mantido como opção
rotulada em vez de simplesmente sumir, os dois erros de URL inválida e o de quantidade de custo
do HUD na validação, `add_completion_rules()`/`completion_rule_enabled()`, e o fallback pra
`PARAM_CLEANHTML` guiado por `formatstringstriptags`), levando `mod_form.php` de 62% pra **100%**.

`restore_playervideo_activity_structure_step` foi de 90% pra 95% do mesmo jeito: 3 testes novos
provam a degradação graciosa de `resolve_hud_item()` em "Duplicar atividade" — uma referência de
item do HUD sobrevive quando o bloco ainda está no curso, e cai pra 0 quando ele (ou o item) some
— mais uma interação corrompida do tipo pergunta (`questionid` ≤ 0, um estado que a própria UI/API
do plugin nunca produz, mas que um XML de backup editado à mão teoricamente poderia) sendo
descartada de forma defensiva em vez de quebrar `resolve_questionid()`. O gap residual de 5% é um
punhado de ramos genuinamente fora de alcance aqui: o caminho "restaurado pelo próprio mapeamento
do block_playerhud" de `resolve_hud_item()` só dispara num backup de *curso inteiro* que também
restaura o bloco do HUD, uma suposição profunda entre plugins que não compensa acoplar a esta
suíte; o ramo "o mapeamento `question_created` do próprio core funcionou" de `resolve_questionid()`
depende da heurística de deduplicação do próprio Moodle, já documentada no SCOPE do plugin como
não-determinística entre versões; e quatro retornos antecipados defensivos
(`process_playervideo_progress`/`_attempt`/`_response` no mapeamento do próprio usuário,
`process_playervideo_polloption` no mapeamento da interação) só disparam num backup com seleção
parcial de usuários, o que exigiria um aparato de teste bem mais pesado que o valor que provaria
— no mesmo espírito da própria nota do `resolve_questionid` sobre comportamento do core, não um
tipo novo de lacuna.

> As classes voltadas pra IA (`ai_service`, `generate_question_ai`, `generate_questions_batch`,
> `generate_response_correction`, `generate_di_summary`) mostram a menor cobertura de linhas
> por PHPUnit do plugin — a maior parte dos seus ramos com provedor real (a escada de fallback
> `local_aihub`/`core_ai`, uma resposta JSON genuinamente malformada, um erro real de limite de
> taxa) é exercitada por validação ao vivo de ponta a ponta contra provedores de IA reais, não
> por uma camada HTTP simulada — o que esta tabela por classe não conta. Fechar essa lacuna com
> PHPUnit puro exigiria injetar um transporte simulável no `ai_service`, uma mudança real de
> arquitetura, não só um teste a mais — deliberadamente não feito, espelhando a mesma escolha já
> tomada pro `mod_playerwords\local\ai_word_generator` irmão. `question_service`/
> `caption_service` têm de forma parecida alguns ramos (o caminho só-4.5 de
> `question_get_default_category()`, um passthrough de VTT já real) que só uma versão
> específica do Moodle ou um formato de entrada específico alcança. A lacuna restante do
> `hud_service` é o caso inverso: seus ramos de degradação graciosa (o retorno antecipado de
> cada método quando o `block_playerhud` está ausente) só rodam de verdade num site onde o
> bloco nunca foi instalado — inertes aqui, já que este site de desenvolvimento o tem.
