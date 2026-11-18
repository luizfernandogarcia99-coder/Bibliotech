// Página "Nova senha" (o link que chega por e-mail).
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

  const form = document.getElementById("form-redefinir");
  if (!form) return; // link inválido: não há formulário

  const erro = document.getElementById("erro-redefinir");
  const msg = document.getElementById("msg-redefinir");

  form.addEventListener("submit", async function (e) {
    e.preventDefault();
    erro.textContent = "";
    msg.textContent = "";

    const botao = form.querySelector("button[type=submit]");
    botao.disabled = true;
    const r = await enviar("redefinir_senha", form);

    if (r.ok) {
      msg.textContent = "Senha alterada! Voltando para o login...";
      setTimeout(function () {
        location.href = location.pathname; // volta para a tela de login
      }, 2000);
    } else {
      botao.disabled = false;
      erro.textContent = r.dados.erro || "Não foi possível salvar a senha.";
    }
  });
})();
