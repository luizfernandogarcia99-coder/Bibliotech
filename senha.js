// Popup "Alterar senha" por código enviado por e-mail.
// A função enviar() vem do script.js, que é carregado antes deste arquivo.
// (os olhos de mostrar/ocultar senha são ligados pelo script.js ou pelo conta.js)
(function () {
  const popup = document.getElementById("popup-senha");
  if (!popup) return;

  const formPedir = document.getElementById("form-codigo-pedir");
  const formConfirmar = document.getElementById("form-codigo-confirmar");
  const erroPedir = document.getElementById("erro-cod1");
  const erroConfirmar = document.getElementById("erro-cod2");
  const msgConfirmar = document.getElementById("msg-cod2");
  const infoConfirmar = document.getElementById("info-cod2");
  const campoEmail = formPedir.querySelector("input[name=email]"); // só existe sem login

  function limpar() {
    formPedir.classList.remove("oculto");
    formConfirmar.classList.add("oculto");
    formPedir.reset();
    formConfirmar.reset();
    erroPedir.textContent = "";
    erroConfirmar.textContent = "";
    msgConfirmar.textContent = "";
    infoConfirmar.textContent = "";

    // volta os campos de senha para "pontinhos"
    popup.querySelectorAll(".campo-senha input").forEach(function (campo) {
      campo.type = "password";
    });
    popup.querySelectorAll(".ver-senha").forEach(function (botao) {
      botao.classList.add("fechado");
      botao.setAttribute("aria-label", "Mostrar senha");
    });
  }

  function abrir() {
    limpar();
    popup.classList.remove("oculto");
    if (campoEmail) campoEmail.focus();
  }

  function fechar() {
    popup.classList.add("oculto");
    limpar();
  }

  document.querySelectorAll("[data-abrir-popup-senha]").forEach(function (el) {
    el.addEventListener("click", function (e) {
      e.preventDefault();
      abrir();
    });
  });

  popup.querySelectorAll("[data-fechar-popup]").forEach(function (el) {
    el.addEventListener("click", fechar);
  });

  document.addEventListener("keydown", function (e) {
    if (e.key === "Escape" && !popup.classList.contains("oculto")) fechar();
  });

  // passo 1: pedir o código
  formPedir.addEventListener("submit", async function (e) {
    e.preventDefault();
    erroPedir.textContent = "";

    const botao = formPedir.querySelector("button[type=submit]");
    botao.disabled = true;
    const r = await enviar("pedir_codigo_senha", new FormData(formPedir));
    botao.disabled = false;

    if (r.ok) {
      infoConfirmar.textContent = r.dados.mensagem;
      formPedir.classList.add("oculto");
      formConfirmar.classList.remove("oculto");
      formConfirmar.querySelector("input[name=codigo]").focus();
    } else {
      erroPedir.textContent = r.dados.erro || "Não foi possível enviar o código.";
    }
  });

  // reenviar o código
  document.getElementById("reenviar-codigo").addEventListener("click", async function () {
    erroConfirmar.textContent = "";
    msgConfirmar.textContent = "";

    const r = await enviar("pedir_codigo_senha", new FormData(formPedir));
    if (r.ok) {
      msgConfirmar.textContent = "Novo código enviado. Use o mais recente.";
    } else {
      erroConfirmar.textContent = r.dados.erro || "Não foi possível reenviar o código.";
    }
  });

  // passo 2: conferir o código e gravar a nova senha
  formConfirmar.addEventListener("submit", async function (e) {
    e.preventDefault();
    erroConfirmar.textContent = "";
    msgConfirmar.textContent = "";

    const corpo = new FormData(formConfirmar);
    if (campoEmail) corpo.append("email", campoEmail.value); // sem login, o e-mail identifica a conta

    const botao = formConfirmar.querySelector("button[type=submit]");
    botao.disabled = true;
    const r = await enviar("confirmar_codigo_senha", corpo);

    if (r.ok) {
      msgConfirmar.textContent = "Senha alterada!";
      setTimeout(function () {
        fechar();
        botao.disabled = false;
        const msgLogin = document.getElementById("msg-ok"); // mensagem verde da tela de login
        if (msgLogin) msgLogin.textContent = "Senha alterada! Entre com a nova senha.";
      }, 1500);
    } else {
      botao.disabled = false;
      erroConfirmar.textContent = r.dados.erro || "Não foi possível alterar a senha.";
    }
  });
})();
