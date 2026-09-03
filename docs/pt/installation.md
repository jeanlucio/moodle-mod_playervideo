# 🛠️ Instalação e Configuração

1. Baixe o arquivo `.zip` ou clone este repositório.
2. Extraia a pasta para o diretório `mod/` do seu Moodle.
3. Renomeie a pasta para `playervideo` (se necessário).
   Caminho final:
   `seu-moodle/mod/playervideo/`
4. Acesse **Administração do site > Notificações** para concluir a instalação.
5. Adicione uma atividade **PlayerVideo** a um curso — o vídeo de origem (URL do YouTube/Vimeo
   ou um arquivo HTML5 enviado), as opções de nota e a integração com o PlayerHUD são todos
   configurados no mesmo formulário da atividade.

Nenhuma atribuição manual de capability é necessária pros papéis padrão: um professor com
edição já ganha `mod/playervideo:manage` (autoria da timeline), `mod/playervideo:reviewresponses`
(corrigir questões abertas) e `mod/playervideo:viewreports` (analytics) por padrão, e um
professor sem edição ganha as duas últimas. Ajuste qualquer uma das seis capabilities em
**Administração do site > Usuários > Permissões > Definir papéis** se a estrutura de papéis da
sua instituição for diferente.

Pra habilitar as funcionalidades assistidas por IA (geração de pergunta, resumos em leitura
fácil, sugestões de correção), instale e configure o
[`local_aihub`](https://github.com/jeanlucio/moodle-local_aihub) ou o próprio subsistema
`core_ai` do Moodle — veja [Requisitos](#requirements) acima. Todo botão de IA simplesmente
fica disponível-mas-inerte até que uma fonte seja configurada; nada mais na atividade depende
disso.
