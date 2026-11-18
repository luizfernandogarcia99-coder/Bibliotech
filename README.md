# Bibliotech

Site de biblioteca feito em **PHP, MySQL, HTML, CSS e JavaScript**, sem frameworks.

## O que tem

- Cadastro e login (senhas com hash) e **acesso separado para administradores**
- Estante com pesquisa, filtros por **autor** e **categoria**, ordem **A–Z** e capas que o administrador reordena arrastando
- Página do livro com sinopse e categoria
- O administrador **adiciona e edita livros** (título, autor, categoria, sinopse, capa e PDF)
- **Aluguel por até 3 dias**, com fila de espera, posição e barra de progresso
- **Leitor online de PDF**, sem botão de baixar
- E-mails com o acesso ao livro (PHPMailer)
- **Alterar senha com código de 6 dígitos** enviado por e-mail, e troca de e-mail com confirmação
- Página "Minha conta"

## Como rodar no seu computador

1. Instale um servidor local com PHP e MySQL (EasyPHP ou XAMPP). O código funciona a partir do PHP 5.5.
2. Copie esta pasta para dentro do servidor (por exemplo, `www/projeto`).
3. No phpMyAdmin, rode o `banco.sql`. Ele cria as tabelas e algumas categorias iniciais.
4. Copie `config_email.example.php` para `config_email.php` e preencha com os dados do seu e-mail. No Gmail, é preciso uma *senha de app*.
5. Ligue a extensão `openssl` no `php.ini` e, se for enviar PDFs grandes, aumente `upload_max_filesize` e `post_max_size`.
6. Abra `http://127.0.0.1/projeto/`, crie uma conta e promova-a a administradora:
   `UPDATE usuarios SET admin = 1 WHERE usuario = 'seu_usuario';`

> Já tinha um banco de uma versão anterior? Use `atualizar_autores.sql` e `atualizar_categorias.sql` em vez do `banco.sql`.

### Categorias

O site **não cria categorias**: o administrador só escolhe entre as que estão na tabela `categorias`.
Para acrescentar uma: `INSERT INTO categorias (nome) VALUES ('Nome');`

## Rodar no GitHub Codespaces

O GitHub guarda o código, mas **não executa PHP nem MySQL** (o GitHub Pages só serve páginas estáticas).
Para testar sem instalar nada, use um Codespace:

1. No repositório, clique em **Code → Codespaces → Create codespace on main**.
2. Espere o ambiente subir (PHP + MariaDB + phpMyAdmin, definidos em `.devcontainer/`). O banco é criado com o `banco.sql`.
3. Na aba **Portas**, abra a porta 80 (o site) ou a 8081 (phpMyAdmin).
4. Crie uma conta e promova-a a administradora pelo phpMyAdmin, como acima.

O Codespace é privado e temporário: serve para desenvolver e testar, não para deixar o site no ar.

## Variáveis de ambiente (opcional)

Para hospedagens e Docker, os dados do banco podem vir de `DB_HOST`, `DB_NAME`, `DB_USER` e `DB_PASS`.
Sem elas, valem `localhost`, `bibliotech`, `root` e senha vazia.

## Segurança

- `config_email.php` guarda uma senha e **não vai para o repositório** (está no `.gitignore`).
- As capas e os PDFs enviados ficam em `capas/` e `pdfs/`, também fora do repositório. A pasta `pdfs/` é bloqueada para acesso direto.
- Antes de publicar na internet: troque `MODO_DEV` para `false` em `livros.php`, crie um usuário do MySQL com senha, use HTTPS e deixe o cookie de sessão como `secure`.
- O leitor dificulta baixar o livro, mas não impede por completo (quem recebe o PDF no navegador pode copiá-lo).

## Direitos autorais

As capas dos livros de exemplo **não estão** no repositório. Use apenas capas e livros que você tem o direito de publicar.

## Créditos

- [PHPMailer 5.2](https://github.com/PHPMailer/PHPMailer) (LGPL), na pasta `PHPMailer`.
- [PDF.js](https://mozilla.github.io/pdf.js/) (Mozilla), carregado por CDN no leitor.
