<?php
// CONFIGURAÇÃO DO E-MAIL DA BIBLIOTECH
// Preencha com os dados do seu provedor. NÃO compartilhe este arquivo
// (ele guarda uma senha) e não o envie para o GitHub.

// Exemplo com Gmail (precisa de "senha de app", veja abaixo).
// Com outros serviços (Brevo, Outlook, etc.), troque o servidor e a porta.
define("SMTP_HOST", "smtp.gmail.com");
define("SMTP_PORTA", 465);        // 465 = conexão segura "ssl" (funciona bem no PHP 5.5)
define("SMTP_SEGURANCA", "ssl");
define("SMTP_USUARIO", "seuemail@gmail.com");
define("SMTP_SENHA", "senha-de-app-aqui");

// Endereço do site, SEM barra no final. É o começo do link enviado por e-mail.
// No seu computador: http://127.0.0.1/projeto
define("URL_SITE", "http://127.0.0.1/projeto");

// COMO CRIAR A SENHA DE APP NO GMAIL:
// 1) Ative a verificação em duas etapas na sua conta Google.
// 2) Acesse a página "Senhas de app" da conta Google, crie uma e cole acima.
//    (a senha normal do Gmail não funciona aqui)
