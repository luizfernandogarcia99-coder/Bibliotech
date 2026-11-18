// Envia um formulário para este mesmo arquivo e devolve { ok, dados }.
async function enviar(acao, formulario) {
  // aceita um <form> ou um FormData já pronto
  const corpo = (formulario instanceof FormData) ? formulario : new FormData(formulario);
  corpo.append("acao", acao);

  let dados;
  let ok = false;
  try {
    const resp = await fetch(location.pathname, { method: "POST", body: corpo });
    ok = resp.ok;
    dados = await resp.json();
  } catch (e) {
    dados = { erro: "Erro de comunicação com o servidor." };
  }
  return { ok: ok, dados: dados };
}

/* ---------- LOGIN / CADASTRO ---------- */
const overlay = document.getElementById("login");

if (overlay) {
  const formLogin = document.getElementById("form-login");
  const formCadastro = document.getElementById("form-cadastro");
  const erroLogin = document.getElementById("erro-login");
  const erroCad = document.getElementById("erro-cad");
  const msgOk = document.getElementById("msg-ok");

  // volta todos os campos de senha para "pontinhos"
  const ocultarSenhas = function () {
    document.querySelectorAll(".campo-senha input").forEach(function (campo) {
      campo.type = "password";
    });
    document.querySelectorAll(".ver-senha").forEach(function (botao) {
      botao.classList.add("fechado");
      botao.setAttribute("aria-label", "Mostrar senha");
    });
  };

  // MOSTRAR / OCULTAR SENHA
  document.querySelectorAll(".ver-senha").forEach(function (botao) {
    botao.addEventListener("click", function () {
      const campo = botao.parentElement.querySelector("input");
      const estaVisivel = campo.type === "text";

      campo.type = estaVisivel ? "password" : "text";
      botao.classList.toggle("fechado", estaVisivel);
      botao.setAttribute("aria-label", estaVisivel ? "Mostrar senha" : "Ocultar senha");
    });
  });

  // alternar entre os formulários
  document.getElementById("ir-cadastro").onclick = function () {
    formLogin.classList.add("oculto");
    formCadastro.classList.remove("oculto");
    erroLogin.textContent = "";
    msgOk.textContent = "";
    ocultarSenhas();
  };

  document.getElementById("ir-login").onclick = function () {
    formCadastro.classList.add("oculto");
    formLogin.classList.remove("oculto");
    erroCad.textContent = "";
    ocultarSenhas();
  };

  // ACESSO DE ADMINISTRADOR
  const formAdmin = document.getElementById("form-admin");
  const erroAdmin = document.getElementById("erro-admin");

  document.getElementById("ir-admin").onclick = function () {
    formLogin.classList.add("oculto");
    formAdmin.classList.remove("oculto");
    erroLogin.textContent = "";
    msgOk.textContent = "";
    ocultarSenhas();
  };

  document.getElementById("voltar-login").onclick = function () {
    formAdmin.classList.add("oculto");
    formLogin.classList.remove("oculto");
    erroAdmin.textContent = "";
    ocultarSenhas();
  };

  formAdmin.addEventListener("submit", async function (e) {
    e.preventDefault();
    erroAdmin.textContent = "";

    const botao = formAdmin.querySelector("button[type=submit]");
    botao.disabled = true;
    const r = await enviar("login_admin", formAdmin);
    botao.disabled = false;

    if (r.ok) {
      location.reload();
    } else {
      erroAdmin.textContent = r.dados.erro || "Usuário ou senha de administrador incorretos.";
    }
  });

  formCadastro.addEventListener("submit", async function (e) {
    e.preventDefault();
    erroCad.textContent = "";

    const botao = formCadastro.querySelector("button[type=submit]");
    botao.disabled = true;
    const r = await enviar("cadastro", formCadastro);
    botao.disabled = false;

    if (r.ok) {
      formCadastro.reset();
      ocultarSenhas();
      formCadastro.classList.add("oculto");
      formLogin.classList.remove("oculto");
      msgOk.textContent = "Conta criada! Faça login.";
    } else {
      erroCad.textContent = r.dados.erro || "Erro ao cadastrar.";
    }
  });

  formLogin.addEventListener("submit", async function (e) {
    e.preventDefault();
    erroLogin.textContent = "";
    msgOk.textContent = "";

    const botao = formLogin.querySelector("button[type=submit]");
    botao.disabled = true;
    const r = await enviar("login", formLogin);
    botao.disabled = false;

    if (r.ok) {
      location.reload(); // o servidor agora entrega a página completa
    } else {
      erroLogin.textContent = r.dados.erro || "Usuário ou senha incorretos.";
    }
  });
}

/* ---------- PESQUISA E FILTROS (AUTOR E CATEGORIA) ---------- */
const campoBusca = document.getElementById("busca");

if (campoBusca) {
  const capas = document.querySelectorAll(".capa");
  const semResultado = document.getElementById("sem-resultado");
  const avisoFiltro = document.getElementById("filtro-info");
  const nomeFiltro = document.getElementById("filtro-info-nome");

  let autorEscolhido = "0";      // "0" = todos
  let categoriaEscolhida = "0";  // "0" = todas
  let nomeAutor = "";
  let nomeCategoria = "";

  // remove acentos e deixa minúsculo: "Ladrão" vira "ladrao"
  function normalizar(texto) {
    return texto
      .toLowerCase()
      .normalize("NFD")
      .replace(/[\u0300-\u036f]/g, "");
  }

  // mostra só as capas que combinam com o texto digitado E com o autor E com a categoria escolhidos
  function filtrar() {
    const termo = normalizar(campoBusca.value.trim());
    let encontrados = 0;

    capas.forEach(function (capa) {
      const textoOk = normalizar(capa.dataset.titulo).includes(termo);
      const autorOk = autorEscolhido === "0" || capa.dataset.autor === autorEscolhido;
      const categoriaOk = categoriaEscolhida === "0" || capa.dataset.categoria === categoriaEscolhida;

      if (textoOk && autorOk && categoriaOk) {
        capa.style.display = "";
        encontrados++;
      } else {
        capa.style.display = "none";
      }
    });

    semResultado.style.display =
      (encontrados === 0 && capas.length > 0) ? "block" : "none";
  }

  // texto "Filtrando por Autor: ... · Categoria: ..." acima das capas
  function atualizarAviso() {
    const partes = [];
    if (autorEscolhido !== "0") partes.push("Autor: " + nomeAutor);
    if (categoriaEscolhida !== "0") partes.push("Categoria: " + nomeCategoria);

    if (avisoFiltro) {
      avisoFiltro.classList.toggle("oculto", partes.length === 0);
      nomeFiltro.textContent = partes.join(" · ");
    }
  }

  campoBusca.addEventListener("input", filtrar);

  // ---- menus "Autores" e "Categorias" (funcionam do mesmo jeito) ----
  const menus = [];

  // "atributo" é o data-... dos itens do painel: "autor" ou "categoria"
  function ligarMenu(idBotao, idPainel, atributo, aoEscolher) {
    const botao = document.getElementById(idBotao);
    const painel = document.getElementById(idPainel);
    if (!botao || !painel) return null;

    const menu = {
      painel: painel,
      fechar: function () {
        painel.classList.add("oculto");
        botao.setAttribute("aria-expanded", "false");
      },
      marcar: function (id) {
        painel.querySelectorAll(".autor-item").forEach(function (item) {
          item.classList.toggle("ativo", item.dataset[atributo] === id);
        });
        botao.classList.toggle("ativo", id !== "0");
      }
    };
    menus.push(menu);

    botao.addEventListener("click", function (e) {
      e.stopPropagation();
      const vaiAbrir = painel.classList.contains("oculto");
      menus.forEach(function (m) { m.fechar(); }); // só um menu aberto por vez
      if (vaiAbrir) {
        painel.classList.remove("oculto");
        botao.setAttribute("aria-expanded", "true");
      }
    });

    painel.addEventListener("click", function (e) {
      const item = e.target.closest("[data-" + atributo + "]");
      if (!item) return;

      aoEscolher(item.dataset[atributo], item.dataset.nome);
      menu.marcar(item.dataset[atributo]);
      menu.fechar();
      atualizarAviso();
      filtrar();
    });

    return menu;
  }

  const menuAutores = ligarMenu("btn-autores", "painel-autores", "autor", function (id, nome) {
    autorEscolhido = id;
    nomeAutor = nome;
  });

  const menuCategorias = ligarMenu("btn-categorias", "painel-categorias", "categoria", function (id, nome) {
    categoriaEscolhida = id;
    nomeCategoria = nome;
  });

  // "limpar filtros" volta autor e categoria para "todos"
  const limpar = document.getElementById("limpar-filtro");
  if (limpar) {
    limpar.addEventListener("click", function () {
      autorEscolhido = "0";
      categoriaEscolhida = "0";
      nomeAutor = "";
      nomeCategoria = "";
      if (menuAutores) menuAutores.marcar("0");
      if (menuCategorias) menuCategorias.marcar("0");
      atualizarAviso();
      filtrar();
    });
  }

  // fecha os menus ao clicar fora ou apertar Esc
  document.addEventListener("click", function (e) {
    menus.forEach(function (m) {
      if (!m.painel.contains(e.target)) m.fechar();
    });
  });
  document.addEventListener("keydown", function (e) {
    if (e.key === "Escape") menus.forEach(function (m) { m.fechar(); });
  });
}

/* ---------- REORDENAR CAPAS (arrastar e soltar) ---------- */
const estante = document.getElementById("estante");

if (estante && estante.classList.contains("editavel")) {
  let arrastando = null;

  const lerOrdem = function () {
    return Array.from(estante.querySelectorAll(".capa")).map(function (c) {
      return c.dataset.id;
    });
  };

  let ordemSalva = lerOrdem().join(",");

  estante.querySelectorAll(".capa").forEach(function (capa) {
    capa.setAttribute("draggable", "true");

    capa.addEventListener("dragstart", function (e) {
      // em ordem alfabética não faz sentido arrastar
      if (estante.classList.contains("ordem-az")) {
        e.preventDefault();
        return;
      }
      arrastando = capa;
      capa.classList.add("arrastando");
      e.dataTransfer.effectAllowed = "move";
      e.dataTransfer.setData("text/plain", capa.dataset.id); // o Firefox exige
    });

    // enquanto arrasta por cima de outra capa, encaixa antes ou depois dela
    capa.addEventListener("dragover", function (e) {
      e.preventDefault();
      if (!arrastando || arrastando === capa) return;

      const caixa = capa.getBoundingClientRect();
      const depois = (e.clientX - caixa.left) > caixa.width / 2;
      estante.insertBefore(arrastando, depois ? capa.nextSibling : capa);
    });

    // ao soltar, salva a nova ordem no banco
    capa.addEventListener("dragend", async function () {
      capa.classList.remove("arrastando");
      arrastando = null;

      const ordemNova = lerOrdem();
      if (ordemNova.join(",") === ordemSalva) return; // nada mudou

      const corpo = new FormData();
      corpo.append("ids", JSON.stringify(ordemNova));
      const r = await enviar("reordenar", corpo);

      if (r.ok) {
        ordemSalva = ordemNova.join(",");
      } else {
        alert(r.dados.erro || "Não foi possível salvar a ordem.");
        location.reload();
      }
    });
  });

  // evita que a imagem seja arrastada sozinha, separada do link
  estante.querySelectorAll(".capa img").forEach(function (img) {
    img.setAttribute("draggable", "false");
  });
}

/* ---------- ORDEM ALFABÉTICA (A–Z) ---------- */
const botaoAZ = document.getElementById("btn-az");

if (estante && botaoAZ) {
  const dicaOrdem = document.getElementById("dica-ordem");
  let ordemManual = Array.from(estante.querySelectorAll(".capa")); // ordem que o administrador definiu

  // sem acentos, sem maiúsculas e sem o artigo do começo:
  // "A Hora da Estrela" é procurada na letra H, "O Hobbit" também
  function chaveTitulo(titulo) {
    return titulo
      .toLowerCase()
      .normalize("NFD")
      .replace(/[\u0300-\u036f]/g, "")
      .replace(/^(a|o|as|os|um|uma)\s+/, "")
      .trim();
  }

  function aplicarOrdem(alfabetica) {
    // antes de ordenar, guarda a ordem manual atual (o administrador pode ter arrastado capas)
    if (alfabetica && !estante.classList.contains("ordem-az")) {
      ordemManual = Array.from(estante.querySelectorAll(".capa"));
    }

    const lista = alfabetica
      ? ordemManual.slice().sort(function (x, y) {
          return chaveTitulo(x.dataset.nome).localeCompare(
            chaveTitulo(y.dataset.nome), "pt-BR", { numeric: true, sensitivity: "base" }
          );
        })
      : ordemManual;

    lista.forEach(function (capa) {
      estante.appendChild(capa);
    });

    estante.classList.toggle("ordem-az", alfabetica);
    botaoAZ.classList.toggle("ativo", alfabetica);
    botaoAZ.setAttribute("aria-pressed", alfabetica ? "true" : "false");
    if (dicaOrdem) dicaOrdem.classList.toggle("oculto", alfabetica);

    try {
      localStorage.setItem("ordemEstante", alfabetica ? "az" : "manual");
    } catch (e) {}
  }

  botaoAZ.addEventListener("click", function () {
    aplicarOrdem(!estante.classList.contains("ordem-az"));
  });

  // o padrão é a ordem alfabética; a escolha fica lembrada neste navegador
  let preferencia = "az";
  try {
    preferencia = localStorage.getItem("ordemEstante") || "az";
  } catch (e) {}
  aplicarOrdem(preferencia === "az");
}

/* ---------- ALUGUEL E FILA ---------- */
const painelAluguel = document.getElementById("aluguel");

if (painelAluguel) {
  const livroId = painelAluguel.dataset.livro;
  const erroAluguel = document.getElementById("erro-aluguel");
  const msgAluguel = document.getElementById("msg-aluguel");
  const barra = painelAluguel.querySelector(".barra-fila-preench");

  // botões: alugar, sair_fila, devolver
  painelAluguel.querySelectorAll("[data-aluguel]").forEach(function (botao) {
    botao.addEventListener("click", async function (e) {
      e.preventDefault();
      const acao = botao.dataset.aluguel;
      if (acao === "devolver" && !confirm("Devolver o livro agora?")) return;

      erroAluguel.textContent = "";
      msgAluguel.textContent = "";
      botao.disabled = true;

      const corpo = new FormData();
      corpo.append("livro_id", livroId);
      const r = await enviar(acao, corpo);

      if (r.ok && acao === "reenviar_acesso") {
        botao.disabled = false;
        msgAluguel.textContent = "E-mail enviado! Confira também a caixa de spam.";
      } else if (r.ok) {
        location.reload();
      } else {
        botao.disabled = false;
        erroAluguel.textContent = r.dados.erro || "Não foi possível concluir.";
      }
    });
  });

  // anima a barra verde ao abrir a página
  if (barra) {
    const alvo = barra.style.width;
    barra.style.width = "0";
    barra.offsetWidth; // força o navegador a aplicar o 0 antes de animar
    barra.style.width = alvo;
  }

  // enquanto está na fila, confere a posição a cada 30 segundos
  if (painelAluguel.dataset.status === "fila") {
    let posAtual = painelAluguel.dataset.pos;

    setInterval(async function () {
      const corpo = new FormData();
      corpo.append("livro_id", livroId);
      const r = await enviar("estado_aluguel", corpo);
      if (!r.ok) return;

      // chegou a sua vez (ou saiu da fila): recarrega a página
      if (r.dados.meu_status !== "fila") {
        location.reload();
        return;
      }

      if (String(r.dados.pos) !== String(posAtual)) {
        posAtual = r.dados.pos;
        document.getElementById("fila-pos").textContent = r.dados.pos;
        document.getElementById("fila-total").textContent = r.dados.fila_total;
        document.getElementById("fila-previsao").textContent = r.dados.previsao_txt;
        barra.style.width = r.dados.pct + "%"; // a barra avança com animação
      }
    }, 30000);
  }
}

/* ---------- EDITAR LIVRO (administrador) ---------- */
const modalEditar = document.getElementById("modal-editar");

if (modalEditar) {
  const formEditar = document.getElementById("form-editar");
  const erroEditar = document.getElementById("erro-editar");

  const fecharEditar = function () {
    modalEditar.classList.add("oculto");
    formEditar.reset(); // volta os campos ao que está salvo
    erroEditar.textContent = "";
  };

  document.getElementById("abrir-editar").onclick = function () {
    modalEditar.classList.remove("oculto");
  };
  document.getElementById("cancelar-editar").onclick = fecharEditar;
  document.addEventListener("keydown", function (e) {
    if (e.key === "Escape") fecharEditar();
  });

  formEditar.addEventListener("submit", async function (e) {
    e.preventDefault();
    erroEditar.textContent = "";

    const botao = formEditar.querySelector("button[type=submit]");
    botao.disabled = true;
    const r = await enviar("editar_livro", formEditar);

    if (r.ok) {
      location.reload(); // mostra o livro já com as alterações
    } else {
      botao.disabled = false;
      erroEditar.textContent = r.dados.erro || "Não foi possível salvar as alterações.";
    }
  });
}

/* ---------- ADICIONAR LIVRO ---------- */
const modal = document.getElementById("modal-livro");

if (modal) {
  const formLivro = document.getElementById("form-livro");
  const erroLivro = document.getElementById("erro-livro");

  const fechar = function () {
    modal.classList.add("oculto");
    formLivro.reset();
    erroLivro.textContent = "";
  };

  document.getElementById("abrir-modal").onclick = function () {
    modal.classList.remove("oculto");
  };
  document.getElementById("cancelar-livro").onclick = fechar;
  document.addEventListener("keydown", function (e) {
    if (e.key === "Escape") fechar();
  });

  formLivro.addEventListener("submit", async function (e) {
    e.preventDefault();
    erroLivro.textContent = "";

    const botao = formLivro.querySelector("button[type=submit]");
    botao.disabled = true;
    const r = await enviar("adicionar", formLivro);
    botao.disabled = false;

    if (r.ok) {
      location.reload(); // recarrega para mostrar o livro novo
    } else {
      erroLivro.textContent = r.dados.erro || "Erro ao adicionar o livro.";
    }
  });
}
