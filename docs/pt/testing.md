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
| `attempt_manager_test.php` | 16 |
| `question_service_test.php` | 15 |
| `caption_service_test.php` | 7 |
| `di_summary_service_test.php` | 3 |
| `blind_mode_service_test.php` | 3 |
| `ai_service_test.php` | 2 |
| **Subtotal** | **46** |

### Testes de Biblioteca, Conclusão, Saída, Privacidade, Backup e Isolamento

| Arquivo de teste | Casos |
|-------------------|------:|
| `lib_test.php` | 15 |
| `privacy/provider_test.php` | 14 |
| `cross_instance_security_test.php` | 6 |
| `backup_restore_test.php` | 5 |
| `completion/custom_completion_test.php` | 4 |
| `uninstall_test.php` | 2 |
| `output/view_render_test.php` | 1 |
| **Subtotal** | **47** |

| **Total Geral** | **204** |

```bash
vendor/bin/phpunit --bootstrap lib/phpunit/bootstrap.php mod/playervideo
```

**Cobertura de linhas por classe (PHPUnit + Xdebug, via a ferramenta `moodle-coverage`):**

| Classe | Cobertura de linhas |
|--------|:--------------------:|
| `external\get_attempt_review` | 100% |
| `external\get_captions` | 100% |
| `external\get_di_summaries` | 100% |
| `external\get_interactions` | 100% |
| `external\get_poll_results` | 100% |
| `external\save_caption` | 100% |
| `external\save_trim` | 100% |
| `local\di_summary_service` | 100% |
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
| `external\submit_answer` | 85% |
| `external\start_attempt` | 80% |
| `local\blind_mode_service` | 78% |
| `completion\custom_completion` | 77% |
| `local\caption_service` | 67% |
| `external\generate_di_summary` | 62% |
| `local\question_service` | 60% |
| `external\generate_questions_batch` | 52% |
| `external\generate_question_ai` | 52% |
| `external\generate_response_correction` | 49% |
| `local\ai_service` | 32% |
| **Geral** | **79%** |

> As classes voltadas pra IA (`ai_service`, `generate_question_ai`, `generate_questions_batch`,
> `generate_response_correction`, `generate_di_summary`) mostram a menor cobertura de linhas
> por PHPUnit do plugin — a maior parte dos seus ramos com provedor real (a escada de fallback
> `local_aihub`/`core_ai`, uma resposta JSON genuinamente malformada, um erro real de limite de
> taxa) é exercitada por validação ao vivo de ponta a ponta contra provedores de IA reais, não
> por uma camada HTTP simulada — o que esta tabela por classe não conta. `question_service`/
> `caption_service` têm de forma parecida alguns ramos (o caminho só-4.5 de
> `question_get_default_category()`, um passthrough de VTT já real) que só uma versão
> específica do Moodle ou um formato de entrada específico alcança. Três classes de domínio
> ainda sem arquivo de teste próprio — `local\hud_service`, `local\intro_service`,
> `local\video_source` — são uma lacuna real e reconhecida, não escondida.
