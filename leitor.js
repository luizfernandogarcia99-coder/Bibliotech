// Leitor online da Bibliotech: desenha cada página do PDF em um <canvas>,
// sem botão de baixar nem de imprimir.
(function () {
  const area = document.getElementById("paginas");
  const info = document.getElementById("pg-info");
  const anterior = document.getElementById("pg-ant");
  const proxima = document.getElementById("pg-prox");

  pdfjsLib.GlobalWorkerOptions.workerSrc =
    "https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js";

  const canvas = document.createElement("canvas");
  area.appendChild(canvas);

  let pdf = null;
  let pagina = 1;
  let ocupado = false;
  let encerrado = false;

  async function mostrar(numero) {
    if (!pdf || ocupado || encerrado) return;
    if (numero < 1 || numero > pdf.numPages) return;

    ocupado = true;
    try {
      pagina = numero;
      const pg = await pdf.getPage(numero);

      // ajusta a página à largura da tela (no máximo 900px)
      const largura = Math.min(area.clientWidth - 16, 900);
      const base = pg.getViewport({ scale: 1 });
      const escala = largura / base.width;
      const dpr = window.devicePixelRatio || 1;
      const vp = pg.getViewport({ scale: escala * dpr });

      canvas.width = vp.width;
      canvas.height = vp.height;
      canvas.style.width = Math.floor(vp.width / dpr) + "px";
      canvas.style.height = Math.floor(vp.height / dpr) + "px";

      await pg.render({ canvasContext: canvas.getContext("2d"), viewport: vp }).promise;

      info.textContent = "Página " + numero + " de " + pdf.numPages;
      anterior.disabled = numero === 1;
      proxima.disabled = numero === pdf.numPages;
      window.scrollTo(0, 0);
    } finally {
      ocupado = false;
    }
  }

  function encerrar(mensagem) {
    encerrado = true;
    if (pdf) pdf.destroy();
    pdf = null;
    area.innerHTML = '<p class="leitor-msg">' + mensagem + "</p>";
    info.textContent = "";
    anterior.disabled = true;
    proxima.disabled = true;
  }

  // o "X-Leitor" é o que o servidor exige para entregar o PDF
  pdfjsLib.getDocument({
    url: area.dataset.url,
    httpHeaders: { "X-Leitor": "1" },
    disableRange: true,
    disableStream: true
  }).promise.then(function (doc) {
    pdf = doc;
    mostrar(1);
  }).catch(function () {
    encerrar("Não foi possível abrir o livro. O seu aluguel pode ter terminado.");
  });

  anterior.addEventListener("click", function () { mostrar(pagina - 1); });
  proxima.addEventListener("click", function () { mostrar(pagina + 1); });

  document.addEventListener("keydown", function (e) {
    if (e.key === "ArrowLeft") mostrar(pagina - 1);
    if (e.key === "ArrowRight") mostrar(pagina + 1);

    // dificulta salvar (Ctrl+S) e imprimir (Ctrl+P)
    if ((e.ctrlKey || e.metaKey) && (e.key === "s" || e.key === "p")) {
      e.preventDefault();
    }
  });

  // quando o prazo do aluguel acaba, fecha o livro na tela
  const segundos = parseInt(area.dataset.segundos, 10);
  if (segundos > 0) {
    setTimeout(function () {
      encerrar("O prazo do aluguel terminou.");
    }, segundos * 1000);
  }
})();
