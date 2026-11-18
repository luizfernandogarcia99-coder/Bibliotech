// Página "Minha conta": nome, e-mail e senha.
// A função enviar() vem do script.js, que é carregado antes deste arquivo.
(function () {
  // olho: mostrar / ocultar senha
  document.querySelectorAll(".ver-senha").forEach(function (botao) {
    botao.addEventListener("click", function () {
      const campo = botao.parentElement.querySelector("input");
      const estaVisivel = campo.type === "text";

      campo.type = estaVisivel ? "password" : "text";
      botao.classList.toggle("fechado", estaVisivel);
      botao.setAttribute("aria-label", estaVisivel ? "Mostrar senha" : "Ocultar senha");
    });
  });

  // volta os campos de senha de um formulário para "pontinhos"
  function ocultarSenhas(form) {
    form.querySelectorAll(".campo-senha input").forEach(function (campo) {
      campo.type = "password";
    });
    form.querySelectorAll(".ver-senha").forEach(function (botao) {
      botao.classList.add("fechado");
      botao.setAttribute("aria-label", "Mostrar senha");
    });
  }

  // liga um formulário a uma ação do servidor
  function ligar(idForm, acao, aoSucesso) {
    const form = document.getElementById(idForm);
    if (!form) return;

    const erro = form.querySelector(".erro");
    const msg = form.querySelector(".msg-ok");

    form.addEventListener("submit", async function (e) {
      e.preventDefault();
      erro.textContent = "";
      msg.textContent = "";

      const botao = form.querySelector("button[type=submit]");
      botao.disabled = true;
      const r = await enviar(acao, form);
      botao.disabled = false;

      if (r.ok) {
        msg.textContent = r.dados.mensagem || "Salvo!";
        if (aoSucesso) aoSucesso(form, r.dados);
      } else {
        erro.textContent = r.dados.erro || "Não foi possível salvar.";
      }
    });
  }

  ligar("form-nome", "salvar_nome", function (form, dados) {
    document.getElementById("nome-barra").textContent = dados.nome;
  });

  ligar("form-email", "pedir_troca_email", function (form) {
    form.reset();
    ocultarSenhas(form);
  });

})();
