<?php
// BIBLIOTECH - tudo em um único arquivo (PHP + HTML + CSS + JavaScript).
// Funciona no PHP 5.5 ou superior.

define("MODO_DEV", true);  // troque para false quando publicar o site
define("DIAS_ALUGUEL", 3); // prazo máximo de cada aluguel, em dias

// Dados do e-mail (servidor SMTP, usuário, senha e endereço do site).
// Ficam em um arquivo separado: veja config_email.php
if (is_file(__DIR__ . "/config_email.php")) {
  require_once __DIR__ . "/config_email.php";
}

session_set_cookie_params(0, "/", "", false, true);
session_start();

$self = basename($_SERVER["SCRIPT_NAME"]); // nome deste arquivo

/* ============================================================
   1. CONEXÃO COM O BANCO
   ============================================================ */
// Os dados do banco podem vir de variáveis de ambiente (hospedagens, Docker, Codespaces).
// Sem elas, valem os valores do seu computador (EasyPHP): localhost, root e senha vazia.
$host         = getenv("DB_HOST") ? getenv("DB_HOST") : "localhost";
$banco        = getenv("DB_NAME") ? getenv("DB_NAME") : "bibliotech";
$usuarioBanco = getenv("DB_USER") ? getenv("DB_USER") : "root";
$senhaBanco   = getenv("DB_PASS") !== false ? getenv("DB_PASS") : ""; // padrão do EasyPHP

try {
  $pdo = new PDO(
    "mysql:host=$host;dbname=$banco;charset=utf8mb4",
    $usuarioBanco,
    $senhaBanco,
    [
      PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
  );
} catch (PDOException $e) {
  http_response_code(500);
  exit(MODO_DEV
    ? "Erro de conexão: " . $e->getMessage()
    : "Não foi possível conectar ao banco de dados.");
}

/* ============================================================
   2. FUNÇÕES AUXILIARES
   ============================================================ */

// Escapa texto antes de mostrar na página (evita XSS).
function h($texto) {
  return htmlspecialchars((string)$texto, ENT_QUOTES, "UTF-8");
}

// Lê um campo enviado por formulário.
function post($campo) {
  return isset($_POST[$campo]) ? $_POST[$campo] : "";
}

// Desenha um campo de senha com o botão do "olho" (mostrar/ocultar).
function campoSenha($nome, $placeholder, $autocomplete) {
  ?>
        <div class="campo-senha">
          <input type="password" name="<?= h($nome) ?>" placeholder="<?= h($placeholder) ?>" required autocomplete="<?= h($autocomplete) ?>">
          <button type="button" class="ver-senha fechado" aria-label="Mostrar senha">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none"
                 stroke="currentColor" stroke-width="2"
                 stroke-linecap="round" stroke-linejoin="round">
              <g class="olho">
                <path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/>
                <circle class="pupila" cx="12" cy="12" r="3"/>
              </g>
            </svg>
          </button>
        </div>
<?php
}

// Confere a senha atual do usuário (usada antes de trocar senha ou e-mail).
function senhaConfere($pdo, $usuarioId, $senha) {
  $stmt = $pdo->prepare("SELECT senha_hash FROM usuarios WHERE id = ?");
  $stmt->execute([$usuarioId]);
  $hash = $stmt->fetchColumn();

  if ($hash && password_verify($senha, $hash)) {
    return true;
  }
  usleep(700000); // pausa nos erros dificulta tentativas em massa
  return false;
}

// Conclui a troca de e-mail usando o código recebido no NOVO endereço.
// Devolve true ou um texto com o motivo da falha.
function confirmarTrocaEmail($pdo, $token, $usuarioId) {
  if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    return "Link inválido ou expirado.";
  }

  $stmt = $pdo->prepare("SELECT id, novo_email FROM mudancas_email
                         WHERE token_hash = ? AND usuario_id = ? AND usado = 0 AND expira_em > NOW()");
  $stmt->execute([hash("sha256", $token), $usuarioId]);
  $pedido = $stmt->fetch();

  if (!$pedido) {
    return "Link inválido, expirado ou de outra conta.";
  }

  $stmt = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE email = ? AND id <> ?");
  $stmt->execute([$pedido["novo_email"], $usuarioId]);
  if ((int)$stmt->fetchColumn() > 0) {
    return "Este e-mail já está em uso por outra conta.";
  }

  try {
    $stmt = $pdo->prepare("UPDATE usuarios SET email = ? WHERE id = ?");
    $stmt->execute([$pedido["novo_email"], $usuarioId]);
  } catch (PDOException $e) {
    return "Este e-mail já está em uso por outra conta.";
  }

  $stmt = $pdo->prepare("UPDATE mudancas_email SET usado = 1 WHERE usuario_id = ?");
  $stmt->execute([$usuarioId]);
  return true;
}

// Gera um código aleatório e imprevisível de 64 caracteres (usado nos links de redefinição).
function tokenAleatorio() {
  if (function_exists("openssl_random_pseudo_bytes")) {
    $bytes = openssl_random_pseudo_bytes(32, $forte);
    if ($bytes !== false && $forte) {
      return bin2hex($bytes);
    }
  }
  if (function_exists("mcrypt_create_iv")) {
    return bin2hex(mcrypt_create_iv(32, MCRYPT_DEV_URANDOM));
  }
  throw new Exception("Sem fonte segura de números aleatórios.");
}

// Envia um e-mail qualquer. Devolve true ou um texto com o motivo da falha.
function enviarEmail($para, $nome, $assunto, $html, $texto) {
  if (!defined("SMTP_HOST") || !defined("URL_SITE")) {
    return "E-mail não configurado (preencha o arquivo config_email.php).";
  }
  if (!is_file(__DIR__ . "/PHPMailer/PHPMailerAutoload.php")) {
    return "A pasta PHPMailer não foi encontrada.";
  }

  try {
    require_once __DIR__ . "/PHPMailer/PHPMailerAutoload.php";

    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = SMTP_HOST;
    $mail->Port       = SMTP_PORTA;
    $mail->SMTPSecure = SMTP_SEGURANCA;
    $mail->SMTPAuth   = true;
    $mail->Username   = SMTP_USUARIO;
    $mail->Password   = SMTP_SENHA;
    $mail->Timeout    = 15;
    $mail->CharSet    = "UTF-8";

    $mail->setFrom(SMTP_USUARIO, "Bibliotech");
    $mail->addAddress($para, $nome);

    $mail->isHTML(true);
    $mail->Subject = $assunto;
    $mail->Body    = $html;
    $mail->AltBody = $texto;
    $mail->send();
  } catch (Exception $ex) {
    error_log("Falha ao enviar e-mail: " . $ex->getMessage());
    return MODO_DEV ? $ex->getMessage() : "Não foi possível enviar o e-mail.";
  }
  return true;
}

// Procura um link de redefinição válido (não usado e dentro do prazo).
// Só o "hash" do código fica no banco: se o banco vazar, os links não vazam junto.
function buscarRedefinicao($pdo, $token) {
  if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    return false;
  }
  $stmt = $pdo->prepare("SELECT id, usuario_id FROM redefinicoes
                         WHERE token_hash = ? AND usado = 0 AND expira_em > NOW()");
  $stmt->execute([hash("sha256", $token)]);
  return $stmt->fetch();
}

// Versão (data de modificação) de um arquivo do site.
// Colocada no final do endereço do CSS/JS, faz o navegador baixar de novo
// sempre que o arquivo mudar, em vez de usar uma cópia antiga guardada.
function versao($arquivo) {
  $caminho = __DIR__ . "/" . $arquivo;
  return is_file($caminho) ? filemtime($caminho) : 0;
}

// Pasta "capas" (cria sozinha, com proteção contra arquivos PHP).
function pastaCapas() {
  $pasta = __DIR__ . "/capas";
  if (!is_dir($pasta)) {
    mkdir($pasta, 0755, true);
    file_put_contents(
      $pasta . "/.htaccess",
      "<FilesMatch \"\\.(php|phtml|phar)$\">\n  Require all denied\n</FilesMatch>\n"
    );
  }
  return $pasta;
}

// Um arquivo foi escolhido no formulário? (campo de arquivo vazio não conta)
function arquivoEnviado($campo) {
  return isset($_FILES[$campo]) && $_FILES[$campo]["error"] !== UPLOAD_ERR_NO_FILE;
}

// Valida e guarda a imagem da capa.
// Devolve ["ok" => true, "caminho" => "capas/arquivo.jpg"] ou ["ok" => false, "erro" => "..."].
function guardarCapa($arq) {
  if ($arq["error"] === UPLOAD_ERR_INI_SIZE || $arq["error"] === UPLOAD_ERR_FORM_SIZE) {
    return ["ok" => false, "erro" => "A imagem é grande demais."];
  }
  if ($arq["error"] !== UPLOAD_ERR_OK) {
    return ["ok" => false, "erro" => "Envie a imagem da capa."];
  }
  if ($arq["size"] > 3 * 1024 * 1024) {
    return ["ok" => false, "erro" => "A capa deve ter no máximo 3 MB."];
  }

  // confere o conteúdo real do arquivo, não só o nome
  $info  = @getimagesize($arq["tmp_name"]);
  $tipos = ["image/jpeg" => "jpg", "image/png" => "png", "image/webp" => "webp"];
  if ($info === false || !isset($tipos[$info["mime"]])) {
    return ["ok" => false, "erro" => "A capa deve ser JPG ou PNG."];
  }

  // nome novo e aleatório: nunca usamos o nome que o usuário mandou
  $nome = md5(uniqid(mt_rand(), true)) . "." . $tipos[$info["mime"]];
  if (!move_uploaded_file($arq["tmp_name"], pastaCapas() . "/" . $nome)) {
    return ["ok" => false, "erro" => "Não foi possível salvar a imagem."];
  }
  return ["ok" => true, "caminho" => "capas/" . $nome];
}

// Valida e guarda o PDF do livro na pasta protegida.
// Devolve ["ok" => true, "nome" => "arquivo.pdf"] ou ["ok" => false, "erro" => "..."].
function guardarPdf($arq) {
  if ($arq["error"] === UPLOAD_ERR_INI_SIZE || $arq["error"] === UPLOAD_ERR_FORM_SIZE) {
    return ["ok" => false, "erro" => "O PDF é maior que o limite do servidor (aumente upload_max_filesize e post_max_size no php.ini)."];
  }
  if ($arq["error"] !== UPLOAD_ERR_OK) {
    return ["ok" => false, "erro" => "Não foi possível receber o PDF."];
  }
  if ($arq["size"] > 50 * 1024 * 1024) {
    return ["ok" => false, "erro" => "O PDF deve ter no máximo 50 MB."];
  }

  // confere o conteúdo: todo PDF começa com "%PDF-"
  $f = fopen($arq["tmp_name"], "rb");
  $cabecalho = fread($f, 5);
  fclose($f);
  if ($cabecalho !== "%PDF-") {
    return ["ok" => false, "erro" => "O arquivo precisa ser um PDF."];
  }

  $nome = md5(uniqid(mt_rand(), true)) . ".pdf";
  if (!move_uploaded_file($arq["tmp_name"], pastaPdfs() . "/" . $nome)) {
    return ["ok" => false, "erro" => "Não foi possível salvar o PDF."];
  }
  return ["ok" => true, "nome" => $nome];
}

// Apaga um arquivo que foi guardado em "capas" ou "pdfs" (ignora se não existir).
function apagarArquivoGuardado($pasta, $nome) {
  if (!$nome) {
    return;
  }
  $caminho = __DIR__ . "/" . $pasta . "/" . basename($nome);
  if (is_file($caminho)) {
    @unlink($caminho);
  }
}

// Acha um registro pelo nome numa tabela de nomes ("autores"), ou cadastra se for novo.
// (as categorias NÃO são criadas pelo site: ficam só na lista do banco). Devolve o id ou null.
function acharOuCriarPorNome($pdo, $tabela, $nome) {
  if ($nome === "" || !in_array($tabela, ["autores"], true)) {
    return null;
  }
  $stmt = $pdo->prepare("SELECT id FROM " . $tabela . " WHERE nome = ?");
  $stmt->execute([$nome]);
  $id = (int)$stmt->fetchColumn();

  if ($id === 0) {
    try {
      $stmt = $pdo->prepare("INSERT INTO " . $tabela . " (nome) VALUES (?)");
      $stmt->execute([$nome]);
      $id = (int)$pdo->lastInsertId();
    } catch (PDOException $e) {
      // outro envio acabou de cadastrar o mesmo nome: busca de novo
      $stmt = $pdo->prepare("SELECT id FROM " . $tabela . " WHERE nome = ?");
      $stmt->execute([$nome]);
      $id = (int)$stmt->fetchColumn();
    }
  }
  return $id ? $id : null;
}

function acharOuCriarAutor($pdo, $nome) {
  return acharOuCriarPorNome($pdo, "autores", $nome);
}

// Cria a pasta protegida "pdfs" (ninguém acessa os arquivos diretamente pelo navegador).
function pastaPdfs() {
  $pasta = __DIR__ . "/pdfs";
  if (!is_dir($pasta)) {
    mkdir($pasta, 0755, true);
    file_put_contents($pasta . "/.htaccess", "Require all denied\n");
  }
  return $pasta;
}

// Confere se o aluguel $id é do usuário logado, está ativo e dentro do prazo.
// Devolve os dados do livro, ou false.
function aluguelLiberado($pdo, $id) {
  if (!isset($_SESSION["usuario_id"])) {
    return false;
  }
  $stmt = $pdo->prepare("SELECT a.livro_id, l.titulo, l.pdf,
                                TIMESTAMPDIFF(SECOND, NOW(), a.expira_em) AS segundos
                         FROM alugueis a
                         JOIN livros l ON l.id = a.livro_id
                         WHERE a.id = ? AND a.usuario_id = ? AND a.status = 'ativo' AND a.expira_em > NOW()");
  $stmt->execute([$id, (int)$_SESSION["usuario_id"]]);
  return $stmt->fetch();
}

// Envia por e-mail o acesso de leitura de um aluguel ativo.
// Devolve true se enviou, ou um texto com o motivo da falha.
function enviarAcessoLivro($pdo, $aluguelId) {
  if (!defined("SMTP_HOST") || !defined("URL_SITE")) {
    return "E-mail não configurado (preencha o arquivo config_email.php).";
  }
  if (!is_file(__DIR__ . "/PHPMailer/PHPMailerAutoload.php")) {
    return "A pasta PHPMailer não foi encontrada.";
  }

  $stmt = $pdo->prepare("SELECT a.id, u.usuario, u.email, l.titulo, l.pdf,
                                DATE_FORMAT(a.expira_em, '%d/%m/%Y às %H:%i') AS expira_txt
                         FROM alugueis a
                         JOIN usuarios u ON u.id = a.usuario_id
                         JOIN livros l ON l.id = a.livro_id
                         WHERE a.id = ? AND a.status = 'ativo'");
  $stmt->execute([$aluguelId]);
  $d = $stmt->fetch();

  if (!$d)           return "Aluguel não encontrado ou já encerrado.";
  if (!$d["email"])  return "Esta conta não tem e-mail cadastrado.";
  if (!$d["pdf"])    return "O PDF deste livro ainda não foi enviado.";

  $link = rtrim(URL_SITE, "/") . "/" . basename($_SERVER["SCRIPT_NAME"]) . "?ler=" . (int)$d["id"];

  try {
    require_once __DIR__ . "/PHPMailer/PHPMailerAutoload.php";

    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = SMTP_HOST;
    $mail->Port       = SMTP_PORTA;
    $mail->SMTPSecure = SMTP_SEGURANCA;
    $mail->SMTPAuth   = true;
    $mail->Username   = SMTP_USUARIO;
    $mail->Password   = SMTP_SENHA;
    $mail->Timeout    = 15;
    $mail->CharSet    = "UTF-8";

    $mail->setFrom(SMTP_USUARIO, "Bibliotech");
    $mail->addAddress($d["email"], $d["usuario"]);

    $mail->isHTML(true);
    $mail->Subject = "Seu acesso ao livro: " . $d["titulo"];
    $mail->Body    = "<p>Olá, <strong>" . h($d["usuario"]) . "</strong>!</p>"
                   . "<p>O livro <strong>" . h($d["titulo"]) . "</strong> está com você até <strong>"
                   . h($d["expira_txt"]) . "</strong>.</p>"
                   . "<p><a href=\"" . h($link) . "\">Clique aqui para ler agora</a></p>"
                   . "<p style=\"color:#666;font-size:13px\">A leitura é feita online, dentro da Bibliotech, "
                   . "e é preciso entrar na sua conta. O livro não pode ser baixado, "
                   . "e o acesso termina quando o prazo acabar.</p>";
    $mail->AltBody = "Olá, " . $d["usuario"] . "!\n\n"
                   . "O livro \"" . $d["titulo"] . "\" está com você até " . $d["expira_txt"] . ".\n"
                   . "Leia agora: " . $link . "\n\n"
                   . "A leitura é online, e é preciso entrar na sua conta. O livro não pode ser baixado.";

    $mail->send();
  } catch (Exception $ex) {
    error_log("Falha ao enviar e-mail: " . $ex->getMessage());
    return MODO_DEV ? $ex->getMessage() : "Não foi possível enviar o e-mail.";
  }

  $stmt = $pdo->prepare("UPDATE alugueis SET email_enviado = 1 WHERE id = ?");
  $stmt->execute([$aluguelId]);
  return true;
}

// Converte minutos em um texto curto: "2d 5h", "3h 20min", "45 min".
function tempoRestante($minutos) {
  $minutos = max(0, (int)$minutos);
  $d = (int)floor($minutos / 1440);
  $h = (int)floor(($minutos % 1440) / 60);
  $m = $minutos % 60;
  if ($d > 0) return $d . "d " . $h . "h";
  if ($h > 0) return $h . "h " . $m . "min";
  return $m . " min";
}

// Atualiza a situação de UM livro:
// 1) encerra o aluguel que passou do prazo;
// 2) se o livro ficou livre, passa a vez para o primeiro da fila.
function atualizarAluguel($pdo, $livroId) {
  $stmt = $pdo->prepare("UPDATE alugueis
                         SET status = 'expirado', livro_ativo = NULL, em_andamento = NULL, encerrado_em = NOW()
                         WHERE livro_id = ? AND status = 'ativo' AND expira_em <= NOW()");
  $stmt->execute([$livroId]);

  $stmt = $pdo->prepare("SELECT COUNT(*) FROM alugueis WHERE livro_id = ? AND status = 'ativo'");
  $stmt->execute([$livroId]);
  if ((int)$stmt->fetchColumn() > 0) {
    return; // continua alugado
  }

  $stmt = $pdo->prepare("SELECT id FROM alugueis WHERE livro_id = ? AND status = 'fila' ORDER BY id LIMIT 1");
  $stmt->execute([$livroId]);
  $proximo = $stmt->fetchColumn();
  if (!$proximo) {
    return; // ninguém esperando
  }

  try {
    $stmt = $pdo->prepare("UPDATE alugueis
                           SET status = 'ativo', livro_ativo = livro_id, inicio = NOW(),
                               expira_em = DATE_ADD(NOW(), INTERVAL " . (int)DIAS_ALUGUEL . " DAY)
                           WHERE id = ? AND status = 'fila'");
    $stmt->execute([$proximo]);
    if ($stmt->rowCount() > 0) {
      enviarAcessoLivro($pdo, $proximo); // avisa por e-mail que chegou a vez
    }
  } catch (PDOException $e) {
    // outra pessoa foi mais rápida: o livro já tem um aluguel ativo
  }
}

// Monta a situação do aluguel de um livro para um usuário (usada na página e na atualização automática).
function estadoAluguel($pdo, $livroId, $usuarioId) {
  atualizarAluguel($pdo, $livroId);

  $e = [
    "ativo"        => false,
    "expira_txt"   => "",
    "restante"     => "",
    "fila_total"   => 0,
    "meu_status"   => "",   // "", "fila" ou "ativo"
    "pos"          => 0,
    "pct"          => 0,    // preenchimento da barra verde (0 a 100)
    "previsao_txt" => "",
    "meu_id"        => 0,
    "email_enviado" => false,
    "tem_pdf"       => false,
  ];

  // quem está com o livro agora
  $stmt = $pdo->prepare("SELECT DATE_FORMAT(expira_em, '%d/%m/%Y às %H:%i') AS expira_txt,
                                TIMESTAMPDIFF(MINUTE, NOW(), expira_em) AS minutos
                         FROM alugueis WHERE livro_id = ? AND status = 'ativo' LIMIT 1");
  $stmt->execute([$livroId]);
  $ativo = $stmt->fetch();
  if ($ativo) {
    $e["ativo"]      = true;
    $e["expira_txt"] = $ativo["expira_txt"];
    $e["restante"]   = tempoRestante($ativo["minutos"]);
  }

  // tamanho da fila
  $stmt = $pdo->prepare("SELECT COUNT(*) FROM alugueis WHERE livro_id = ? AND status = 'fila'");
  $stmt->execute([$livroId]);
  $e["fila_total"] = (int)$stmt->fetchColumn();

  // o livro já tem PDF enviado?
  $stmt = $pdo->prepare("SELECT pdf FROM livros WHERE id = ?");
  $stmt->execute([$livroId]);
  $e["tem_pdf"] = (bool)$stmt->fetchColumn();

  // o pedido deste usuário (na fila ou ativo)
  $stmt = $pdo->prepare("SELECT id, status, pos_entrada, email_enviado FROM alugueis
                         WHERE livro_id = ? AND usuario_id = ? AND em_andamento = 1 LIMIT 1");
  $stmt->execute([$livroId, $usuarioId]);
  $meu = $stmt->fetch();

  if ($meu) {
    $e["meu_status"]    = $meu["status"];
    $e["meu_id"]        = (int)$meu["id"];
    $e["email_enviado"] = (bool)$meu["email_enviado"];

    if ($meu["status"] === "ativo") {
      $e["pct"] = 100;
    } elseif ($meu["status"] === "fila") {
      // posição = quantos pedidos da fila vieram antes de mim (eu incluído)
      $stmt = $pdo->prepare("SELECT COUNT(*) FROM alugueis WHERE livro_id = ? AND status = 'fila' AND id <= ?");
      $stmt->execute([$livroId, $meu["id"]]);
      $pos = max(1, (int)$stmt->fetchColumn());
      $e["pos"] = $pos;

      // barra: começa pequena ao entrar e chega a 100% quando o livro é seu
      $entrada = max(1, (int)$meu["pos_entrada"]);
      $pct = (int)round(100 * ($entrada - $pos + 1) / ($entrada + 1));
      $e["pct"] = min(99, max(0, $pct));

      // previsão máxima: fim do aluguel atual + 3 dias para cada pessoa na minha frente
      if ($ativo) {
        $dias = ($pos - 1) * (int)DIAS_ALUGUEL;
        $stmt = $pdo->prepare("SELECT DATE_FORMAT(DATE_ADD(expira_em, INTERVAL " . $dias . " DAY), '%d/%m/%Y às %H:%i')
                               FROM alugueis WHERE livro_id = ? AND status = 'ativo' LIMIT 1");
        $stmt->execute([$livroId]);
        $e["previsao_txt"] = (string)$stmt->fetchColumn();
      }
    }
  }

  return $e;
}

// "luiz@gmail.com" vira "l***@gmail.com" (para mostrar sem expor o endereço inteiro).
function mascararEmail($email) {
  if (!$email || strpos($email, "@") === false) {
    return "";
  }
  list($parte, $dominio) = explode("@", $email, 2);
  return substr($parte, 0, 1) . str_repeat("*", max(2, strlen($parte) - 1)) . "@" . $dominio;
}

// Compara dois textos sem revelar, pelo tempo de resposta, em que posição eles diferem.
function compararSeguro($a, $b) {
  if (function_exists("hash_equals")) {
    return hash_equals($a, $b);
  }
  if (strlen($a) !== strlen($b)) {
    return false;
  }
  $r = 0;
  for ($i = 0; $i < strlen($a); $i++) {
    $r |= ord($a[$i]) ^ ord($b[$i]);
  }
  return $r === 0;
}

// Popup "Alterar senha": 1) pede o código por e-mail, 2) digita o código e a nova senha.
// Quem está logado não digita o e-mail: o código vai para o e-mail da própria conta.
function popupSenhaCodigo($logado, $emailMascarado = "") {
  ?>
    <div class="login-overlay popup-codigo oculto" id="popup-senha">
      <div class="login-caixa">
        <h2>Alterar senha</h2>

        <form id="form-codigo-pedir">
          <?php if ($logado): ?>
            <?php if ($emailMascarado !== ""): ?>
              <p class="texto-ajuda">Enviaremos um código de 6 dígitos para o e-mail da sua conta: <strong><?= h($emailMascarado) ?></strong>.</p>
            <?php else: ?>
              <p class="texto-ajuda">Sua conta ainda não tem e-mail cadastrado. Cadastre um na seção &ldquo;E-mail&rdquo; desta página.</p>
            <?php endif; ?>
          <?php else: ?>
            <p class="texto-ajuda">Informe o e-mail da sua conta. Enviaremos um código de 6 dígitos para ele.</p>
            <input type="email" name="email" placeholder="E-mail da conta" required autocomplete="email">
          <?php endif; ?>
          <p class="erro" id="erro-cod1"></p>
          <?php if (!$logado || $emailMascarado !== ""): ?>
            <button type="submit">Enviar código</button>
          <?php endif; ?>
          <button type="button" class="secundario" data-fechar-popup>Cancelar</button>
        </form>

        <form id="form-codigo-confirmar" class="oculto">
          <p class="texto-ajuda" id="info-cod2"></p>
          <input type="text" class="codigo-input" name="codigo" inputmode="numeric" maxlength="6"
                 placeholder="000000" required autocomplete="one-time-code">
          <?php campoSenha("senha", "Nova senha (mín. 6 caracteres)", "new-password"); ?>
          <?php campoSenha("senha2", "Confirmar nova senha", "new-password"); ?>
          <p class="erro" id="erro-cod2"></p>
          <p class="msg-ok" id="msg-cod2"></p>
          <button type="submit">Salvar nova senha</button>
          <p class="alternar"><a id="reenviar-codigo">Reenviar código</a></p>
          <button type="button" class="secundario" data-fechar-popup>Cancelar</button>
        </form>
      </div>
    </div>
<?php
}

// Confere NO BANCO se o usuário logado é administrador.
// (consultar o banco a cada vez evita confiar em algo que o navegador possa alterar)
function ehAdmin($pdo) {
  // só vale para quem entrou pelo acesso de administrador
  if (!isset($_SESSION["usuario_id"]) || !isset($_SESSION["papel"]) || $_SESSION["papel"] !== "admin") {
    return false;
  }
  $stmt = $pdo->prepare("SELECT admin FROM usuarios WHERE id = ?");
  $stmt->execute([$_SESSION["usuario_id"]]);
  return (bool)$stmt->fetchColumn();
}

// Responde em JSON e encerra o script.
function responder($dados, $codigo = 200) {
  http_response_code($codigo);
  header("Content-Type: application/json; charset=utf-8");
  echo json_encode($dados);
  exit;
}

/* ============================================================
   3. AÇÕES (sair, login, cadastro, adicionar livro)
   ============================================================ */

// SAIR
if (isset($_GET["sair"])) {
  $_SESSION = [];
  session_destroy();
  header("Location: " . $self);
  exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $acao = post("acao");

  // se os arquivos passaram do limite do servidor (post_max_size), o PHP descarta tudo e a requisição chega vazia
  if (empty($_POST) && empty($_FILES) && isset($_SERVER["CONTENT_LENGTH"]) && (int)$_SERVER["CONTENT_LENGTH"] > 0) {
    responder(["erro" => "Os arquivos enviados passam do limite do servidor. Aumente upload_max_filesize e post_max_size no php.ini."], 413);
  }

  // ---------- LOGIN ----------
  if ($acao === "login") {
    $usuario = strtolower(trim(post("usuario")));
    $senha   = post("senha");

    $stmt = $pdo->prepare("SELECT id, usuario, senha_hash, admin FROM usuarios WHERE usuario = ?");
    $stmt->execute([$usuario]);
    $u = $stmt->fetch();

    if ($u && password_verify($senha, $u["senha_hash"])) {
      // contas de administrador entram só pelo acesso de administrador
      if ((int)$u["admin"] === 1) {
        responder(["erro" => "Esta conta é de administrador. Use o acesso de administrador."], 403);
      }
      session_regenerate_id(true);
      $_SESSION["usuario_id"] = (int)$u["id"];
      $_SESSION["usuario"]    = $u["usuario"];
      $_SESSION["papel"]      = "usuario";
      responder(["ok" => true]);
    }
    usleep(700000); // pequena pausa nos erros dificulta tentativas em massa
    responder(["erro" => "Usuário ou senha incorretos."], 401);
  }

  // ---------- ALTERAR SENHA POR CÓDIGO: pedir o código por e-mail ----------
  if ($acao === "pedir_codigo_senha") {
    $logadoAgora = isset($_SESSION["usuario_id"]);

    if ($logadoAgora) {
      $stmt = $pdo->prepare("SELECT id, usuario, email FROM usuarios WHERE id = ?");
      $stmt->execute([(int)$_SESSION["usuario_id"]]);
    } else {
      $email = strtolower(trim(post("email")));
      if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        responder(["erro" => "Informe um e-mail válido."], 400);
      }
      $stmt = $pdo->prepare("SELECT id, usuario, email FROM usuarios WHERE email = ?");
      $stmt->execute([$email]);
    }
    $u = $stmt->fetch();

    if ($logadoAgora && (!$u || !$u["email"])) {
      responder(["erro" => "Sua conta não tem e-mail cadastrado. Cadastre um na seção E-mail."], 400);
    }

    if ($u && $u["email"]) {
      // no máximo 3 códigos por hora, com 1 minuto de espera entre um e outro
      $stmt = $pdo->prepare("SELECT COUNT(*) FROM codigos_senha
                             WHERE usuario_id = ? AND criado_em > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
      $stmt->execute([$u["id"]]);
      $pedidos = (int)$stmt->fetchColumn();

      $stmt = $pdo->prepare("SELECT COUNT(*) FROM codigos_senha
                             WHERE usuario_id = ? AND criado_em > DATE_SUB(NOW(), INTERVAL 60 SECOND)");
      $stmt->execute([$u["id"]]);
      $recente = (int)$stmt->fetchColumn();

      if ($pedidos >= 3 || $recente > 0) {
        if ($logadoAgora) {
          responder(["erro" => $recente > 0
            ? "Aguarde 1 minuto antes de pedir outro código."
            : "Muitos pedidos seguidos. Tente de novo mais tarde."], 429);
        }
        // sem login: não revela nada, apenas não envia outro código
      } else {
        try {
          $tok = tokenAleatorio();
        } catch (Exception $ex) {
          responder(["erro" => "Não foi possível gerar o código agora."], 500);
        }
        $codigo = str_pad((string)(int)fmod(hexdec(substr($tok, 0, 8)), 1000000), 6, "0", STR_PAD_LEFT);

        // o código novo anula os anteriores
        $stmt = $pdo->prepare("UPDATE codigos_senha SET usado = 1 WHERE usuario_id = ? AND usado = 0");
        $stmt->execute([$u["id"]]);

        // no banco fica só o hash do código, nunca o código em si
        $stmt = $pdo->prepare("INSERT INTO codigos_senha (usuario_id, codigo_hash, expira_em, criado_em)
                               VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE), NOW())");
        $stmt->execute([$u["id"], hash("sha256", $u["id"] . ":" . $codigo)]);

        $resultado = enviarEmail(
          $u["email"],
          $u["usuario"],
          "Seu código para alterar a senha - Bibliotech",
          "<p>Olá, <strong>" . h($u["usuario"]) . "</strong>!</p>"
          . "<p>Seu código para alterar a senha na Bibliotech é:</p>"
          . "<p style=\"font-size:28px;letter-spacing:6px;font-weight:bold\">" . $codigo . "</p>"
          . "<p style=\"color:#666;font-size:13px\">O código vale por 10 minutos. Se não foi você quem pediu, "
          . "ignore este e-mail: ninguém consegue alterar a senha sem o código.</p>",
          "Olá, " . $u["usuario"] . "!\n\nSeu código para alterar a senha na Bibliotech: " . $codigo . "\n"
          . "Vale por 10 minutos. Se não foi você quem pediu, ignore este e-mail."
        );

        if ($resultado !== true && (MODO_DEV || $logadoAgora)) {
          responder(["erro" => $resultado], 500);
        }
      }
    } elseif (!$logadoAgora) {
      usleep(700000); // pequena pausa, para não denunciar se o e-mail existe
    }

    responder([
      "ok" => true,
      "mensagem" => "Se o e-mail estiver cadastrado, enviamos um código de 6 dígitos. "
                  . "Ele vale por 10 minutos. Confira também a caixa de spam."
    ]);
  }

  // ---------- ALTERAR SENHA POR CÓDIGO: conferir o código e gravar a nova senha ----------
  if ($acao === "confirmar_codigo_senha") {
    $codigo = preg_replace('/\D/', '', post("codigo"));
    $senha  = post("senha");
    $senha2 = post("senha2");

    if (strlen($codigo) !== 6) {
      responder(["erro" => "Digite o código de 6 dígitos."], 400);
    }
    if (strlen($senha) < 6) {
      responder(["erro" => "A nova senha precisa ter pelo menos 6 caracteres."], 400);
    }
    if ($senha !== $senha2) {
      responder(["erro" => "As senhas não coincidem."], 400);
    }

    if (isset($_SESSION["usuario_id"])) {
      $usuarioId = (int)$_SESSION["usuario_id"];
    } else {
      $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE email = ?");
      $stmt->execute([strtolower(trim(post("email")))]);
      $usuarioId = (int)$stmt->fetchColumn();
    }

    $invalido = "Código inválido ou expirado. Peça um novo código.";
    if ($usuarioId <= 0) {
      usleep(700000);
      responder(["erro" => $invalido], 400);
    }

    $stmt = $pdo->prepare("SELECT id, codigo_hash, tentativas FROM codigos_senha
                           WHERE usuario_id = ? AND usado = 0 AND expira_em > NOW()
                           ORDER BY id DESC LIMIT 1");
    $stmt->execute([$usuarioId]);
    $reg = $stmt->fetch();
    if (!$reg) {
      usleep(700000);
      responder(["erro" => $invalido], 400);
    }

    // no máximo 5 tentativas por código
    if ((int)$reg["tentativas"] >= 5) {
      $stmt = $pdo->prepare("UPDATE codigos_senha SET usado = 1 WHERE id = ?");
      $stmt->execute([$reg["id"]]);
      responder(["erro" => "Muitas tentativas erradas. Peça um novo código."], 429);
    }

    if (!compararSeguro($reg["codigo_hash"], hash("sha256", $usuarioId . ":" . $codigo))) {
      $stmt = $pdo->prepare("UPDATE codigos_senha SET tentativas = tentativas + 1 WHERE id = ?");
      $stmt->execute([$reg["id"]]);
      usleep(700000);
      $restam = 4 - (int)$reg["tentativas"];
      responder(["erro" => $restam > 0
        ? "Código incorreto. Restam " . $restam . " tentativa(s)."
        : "Código incorreto. Peça um novo código."], 400);
    }

    // código certo: grava a senha nova e encerra todos os códigos e links pendentes
    $stmt = $pdo->prepare("UPDATE usuarios SET senha_hash = ? WHERE id = ?");
    $stmt->execute([password_hash($senha, PASSWORD_DEFAULT), $usuarioId]);

    $stmt = $pdo->prepare("UPDATE codigos_senha SET usado = 1 WHERE usuario_id = ?");
    $stmt->execute([$usuarioId]);
    $stmt = $pdo->prepare("UPDATE redefinicoes SET usado = 1 WHERE usuario_id = ?");
    $stmt->execute([$usuarioId]);

    if (isset($_SESSION["usuario_id"])) {
      session_regenerate_id(true);
    }

    // aviso por e-mail (se o envio funcionar)
    $stmt = $pdo->prepare("SELECT usuario, email FROM usuarios WHERE id = ?");
    $stmt->execute([$usuarioId]);
    $eu = $stmt->fetch();
    if ($eu && $eu["email"]) {
      enviarEmail(
        $eu["email"],
        $eu["usuario"],
        "Sua senha foi alterada - Bibliotech",
        "<p>Olá, <strong>" . h($eu["usuario"]) . "</strong>!</p>"
        . "<p>A senha da sua conta na Bibliotech acabou de ser alterada.</p>"
        . "<p style=\"color:#666;font-size:13px\">Se não foi você, peça um novo código em &ldquo;Esqueci minha senha&rdquo;.</p>",
        "Olá, " . $eu["usuario"] . "!\n\nA senha da sua conta na Bibliotech acabou de ser alterada.\n"
        . "Se não foi você, peça um novo código em \"Esqueci minha senha\"."
      );
    }

    responder(["ok" => true, "mensagem" => "Senha alterada!"]);
  }

  // ---------- ESQUECI MINHA SENHA: envia o link por e-mail ----------
  if ($acao === "esqueci_senha") {
    $email = strtolower(trim(post("email")));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      responder(["erro" => "Informe um e-mail válido."], 400);
    }

    $stmt = $pdo->prepare("SELECT id, usuario FROM usuarios WHERE email = ?");
    $stmt->execute([$email]);
    $u = $stmt->fetch();

    if ($u) {
      // no máximo 3 pedidos por hora para a mesma conta
      $stmt = $pdo->prepare("SELECT COUNT(*) FROM redefinicoes
                             WHERE usuario_id = ? AND criado_em > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
      $stmt->execute([$u["id"]]);

      if ((int)$stmt->fetchColumn() < 3) {
        try {
          $token = tokenAleatorio();
        } catch (Exception $ex) {
          responder(["erro" => "Não foi possível gerar o link agora."], 500);
        }

        $stmt = $pdo->prepare("INSERT INTO redefinicoes (usuario_id, token_hash, expira_em, criado_em)
                               VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR), NOW())");
        $stmt->execute([$u["id"], hash("sha256", $token)]);

        $base = defined("URL_SITE") ? rtrim(URL_SITE, "/") : "";
        $link = $base . "/" . basename($_SERVER["SCRIPT_NAME"]) . "?redefinir=" . $token;

        $resultado = enviarEmail(
          $email,
          $u["usuario"],
          "Redefinição de senha - Bibliotech",
          "<p>Olá, <strong>" . h($u["usuario"]) . "</strong>!</p>"
          . "<p>Recebemos um pedido para criar uma nova senha na sua conta da Bibliotech.</p>"
          . "<p><a href=\"" . h($link) . "\">Criar nova senha</a></p>"
          . "<p style=\"color:#666;font-size:13px\">O link vale por 1 hora e só funciona uma vez. "
          . "Se não foi você quem pediu, ignore este e-mail: a sua senha continua a mesma.</p>",
          "Olá, " . $u["usuario"] . "!\n\n"
          . "Para criar uma nova senha na Bibliotech, abra o link (vale por 1 hora e só funciona uma vez):\n"
          . $link . "\n\n"
          . "Se não foi você quem pediu, ignore este e-mail."
        );

        if ($resultado !== true && MODO_DEV) {
          responder(["erro" => $resultado], 500); // durante os testes, mostra o motivo
        }
      }
    } else {
      usleep(700000); // pequena pausa, para não denunciar se o e-mail existe
    }

    // a resposta é sempre a mesma: não revela quais e-mails estão cadastrados
    responder([
      "ok" => true,
      "mensagem" => "Se este e-mail estiver cadastrado, enviamos um link para criar a nova senha. "
                  . "Ele vale por 1 hora. Confira também a caixa de spam."
    ]);
  }

  // ---------- REDEFINIR SENHA: grava a nova senha usando o link ----------
  if ($acao === "redefinir_senha") {
    $senha  = post("senha");
    $senha2 = post("senha2");

    if (strlen($senha) < 6) {
      responder(["erro" => "A senha precisa ter pelo menos 6 caracteres."], 400);
    }
    if ($senha !== $senha2) {
      responder(["erro" => "As senhas não coincidem."], 400);
    }

    $registro = buscarRedefinicao($pdo, post("token"));
    if (!$registro) {
      usleep(700000);
      responder(["erro" => "Link inválido ou expirado. Peça um novo em \"Esqueci minha senha\"."], 400);
    }

    $stmt = $pdo->prepare("UPDATE usuarios SET senha_hash = ? WHERE id = ?");
    $stmt->execute([password_hash($senha, PASSWORD_DEFAULT), $registro["usuario_id"]]);

    // o link (e qualquer outro pendente dessa conta) deixa de valer
    $stmt = $pdo->prepare("UPDATE redefinicoes SET usado = 1 WHERE usuario_id = ?");
    $stmt->execute([$registro["usuario_id"]]);

    responder(["ok" => true]);
  }

  // ---------- LOGIN DE ADMINISTRADOR ----------
  if ($acao === "login_admin") {
    $usuario = strtolower(trim(post("usuario")));
    $senha   = post("senha");

    $stmt = $pdo->prepare("SELECT id, usuario, senha_hash, admin FROM usuarios WHERE usuario = ?");
    $stmt->execute([$usuario]);
    $u = $stmt->fetch();

    if ($u && (int)$u["admin"] === 1 && password_verify($senha, $u["senha_hash"])) {
      session_regenerate_id(true);
      $_SESSION["usuario_id"] = (int)$u["id"];
      $_SESSION["usuario"]    = $u["usuario"];
      $_SESSION["papel"]      = "admin";
      responder(["ok" => true]);
    }
    usleep(700000);
    // mesma mensagem para senha errada e para conta que não é de administrador
    responder(["erro" => "Usuário ou senha de administrador incorretos."], 401);
  }

  // ---------- CADASTRO ----------
  if ($acao === "cadastro") {
    $usuario = strtolower(trim(post("usuario")));
    $email   = strtolower(trim(post("email")));
    $senha   = post("senha");
    $senha2  = post("senha2");

    if (!preg_match('/^[a-z0-9_]{3,30}$/', $usuario)) {
      responder(["erro" => "Usuário: 3 a 30 caracteres, só letras, números e _."], 400);
    }
    if (strlen($email) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
      responder(["erro" => "Informe um e-mail válido."], 400);
    }
    if (strlen($senha) < 6) {
      responder(["erro" => "A senha precisa ter pelo menos 6 caracteres."], 400);
    }
    if ($senha !== $senha2) {
      responder(["erro" => "As senhas não coincidem."], 400);
    }

    try {
      // confere antes se o usuário ou o e-mail já existem
      $stmt = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE usuario = ?");
      $stmt->execute([$usuario]);
      if ((int)$stmt->fetchColumn() > 0) {
        responder(["erro" => "Esse usuário já existe."], 409);
      }
      $stmt = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE email = ?");
      $stmt->execute([$email]);
      if ((int)$stmt->fetchColumn() > 0) {
        responder(["erro" => "Este e-mail já está cadastrado."], 409);
      }

      $stmt = $pdo->prepare("INSERT INTO usuarios (usuario, email, senha_hash) VALUES (?, ?, ?)");
      $stmt->execute([$usuario, $email, password_hash($senha, PASSWORD_DEFAULT)]);
      responder(["ok" => true]);
    } catch (PDOException $e) {
      if ($e->getCode() === "23000") { // UNIQUE violado: usuário já existe
        responder(["erro" => "Usuário ou e-mail já cadastrado."], 409);
      }
      responder(["erro" => MODO_DEV ? $e->getMessage() : "Erro ao cadastrar."], 500);
    }
  }

  // ---------- ADICIONAR LIVRO ----------
  if ($acao === "adicionar") {
    // A checagem de login é feita aqui, no servidor.
    if (!isset($_SESSION["usuario"])) {
      responder(["erro" => "Faça login para adicionar livros."], 401);
    }
    if (!ehAdmin($pdo)) {
      responder(["erro" => "Você não tem permissão para adicionar livros."], 403);
    }

    $titulo  = trim(post("titulo"));
    $autor   = preg_replace('/\s+/', ' ', trim(post("autor")));
    $categoriaId = (int)post("categoria_id"); // código escolhido na lista (0 = sem categoria)
    $sinopse = trim(post("sinopse"));

    if ($titulo === "" || mb_strlen($titulo) > 200) {
      responder(["erro" => "Informe o título (até 200 caracteres)."], 400);
    }
    if (mb_strlen($autor) > 150) {
      responder(["erro" => "O autor pode ter até 150 caracteres."], 400);
    }
    // a categoria só pode ser uma das que já estão cadastradas (ou nenhuma); nunca é criada aqui
    if ($categoriaId > 0) {
      $stmt = $pdo->prepare("SELECT COUNT(*) FROM categorias WHERE id = ?");
      $stmt->execute([$categoriaId]);
      if ((int)$stmt->fetchColumn() === 0) {
        responder(["erro" => "Categoria inválida. Escolha uma da lista."], 400);
      }
    } else {
      $categoriaId = null;
    }

    // PDF (opcional): confere antes de guardar qualquer outro arquivo
    $nomePdf = null;
    if (arquivoEnviado("pdf")) {
      $r = guardarPdf($_FILES["pdf"]);
      if (!$r["ok"]) {
        responder(["erro" => $r["erro"]], 400);
      }
      $nomePdf = $r["nome"];
    }

    // capa (obrigatória)
    if (!isset($_FILES["capa"])) {
      apagarArquivoGuardado("pdfs", $nomePdf);
      responder(["erro" => "Envie a imagem da capa."], 400);
    }
    $r = guardarCapa($_FILES["capa"]);
    if (!$r["ok"]) {
      apagarArquivoGuardado("pdfs", $nomePdf); // não deixa o PDF solto se a capa falhou
      responder(["erro" => $r["erro"]], 400);
    }
    $caminhoCapa = $r["caminho"];

    // acha o autor na tabela autores (ou cadastra, se for novo)
    $autorId = acharOuCriarAutor($pdo, $autor);

    // o livro novo entra no fim da estante
    $proxima = (int)$pdo->query("SELECT COALESCE(MAX(ordem), 0) + 1 FROM livros")->fetchColumn();

    $stmt = $pdo->prepare("INSERT INTO livros (titulo, autor_id, categoria_id, sinopse, capa, pdf, ordem) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
      $titulo,
      $autorId,
      $categoriaId,
      $sinopse !== "" ? $sinopse : null,
      $caminhoCapa,
      $nomePdf,
      $proxima,
    ]);
    responder(["ok" => true]);
  }

  // ---------- ALUGUEL (pedir, sair da fila, devolver, ver situação) ----------
  if ($acao === "alugar" || $acao === "sair_fila" || $acao === "devolver" || $acao === "estado_aluguel" || $acao === "reenviar_acesso") {
    if (!isset($_SESSION["usuario_id"])) {
      responder(["erro" => "Faça login."], 401);
    }

    $livroId   = (int)post("livro_id");
    $usuarioId = (int)$_SESSION["usuario_id"];

    $stmt = $pdo->prepare("SELECT id FROM livros WHERE id = ?");
    $stmt->execute([$livroId]);
    if (!$stmt->fetch()) {
      responder(["erro" => "Livro não encontrado."], 404);
    }

    // PEDIR ALUGUEL: se o livro está livre, já é seu; se não, você entra na fila
    if ($acao === "alugar") {
      atualizarAluguel($pdo, $livroId);

      $stmt = $pdo->prepare("SELECT id FROM alugueis WHERE livro_id = ? AND usuario_id = ? AND em_andamento = 1");
      $stmt->execute([$livroId, $usuarioId]);
      if ($stmt->fetch()) {
        responder(["erro" => "Você já tem um pedido para este livro."], 409);
      }

      $stmt = $pdo->prepare("SELECT COUNT(*) FROM alugueis WHERE livro_id = ? AND status = 'ativo'");
      $stmt->execute([$livroId]);
      $temAtivo = (int)$stmt->fetchColumn() > 0;

      $stmt = $pdo->prepare("SELECT COUNT(*) FROM alugueis WHERE livro_id = ? AND status = 'fila'");
      $stmt->execute([$livroId]);
      $tamanhoFila = (int)$stmt->fetchColumn();

      $entrou = false;
      $novoId = 0;
      if (!$temAtivo && $tamanhoFila === 0) {
        try {
          $stmt = $pdo->prepare("INSERT INTO alugueis
              (livro_id, usuario_id, status, pos_entrada, criado_em, inicio, expira_em, livro_ativo, em_andamento)
              VALUES (?, ?, 'ativo', 1, NOW(), NOW(), DATE_ADD(NOW(), INTERVAL " . (int)DIAS_ALUGUEL . " DAY), ?, 1)");
          $stmt->execute([$livroId, $usuarioId, $livroId]);
          $novoId = (int)$pdo->lastInsertId();
          $entrou = true;
        } catch (PDOException $e) {
          if ($e->getCode() !== "23000") {
            throw $e;
          }
          // alguém alugou no mesmo instante: cai para a fila
        }
      }

      if (!$entrou) {
        try {
          $stmt = $pdo->prepare("INSERT INTO alugueis (livro_id, usuario_id, status, pos_entrada, criado_em, em_andamento)
                                 VALUES (?, ?, 'fila', ?, NOW(), 1)");
          $stmt->execute([$livroId, $usuarioId, $tamanhoFila + 1]);
        } catch (PDOException $e) {
          if ($e->getCode() === "23000") {
            responder(["erro" => "Você já tem um pedido para este livro."], 409);
          }
          throw $e;
        }
      }
      if ($entrou) {
        enviarAcessoLivro($pdo, $novoId); // o livro já é seu: manda o acesso por e-mail
      }
      responder(["ok" => true]);
    }

    // REENVIAR O E-MAIL DE ACESSO
    if ($acao === "reenviar_acesso") {
      $stmt = $pdo->prepare("SELECT id FROM alugueis WHERE livro_id = ? AND usuario_id = ? AND status = 'ativo'");
      $stmt->execute([$livroId, $usuarioId]);
      $meuAluguel = $stmt->fetchColumn();
      if (!$meuAluguel) {
        responder(["erro" => "Você não está com este livro."], 400);
      }
      $resultado = enviarAcessoLivro($pdo, (int)$meuAluguel);
      if ($resultado !== true) {
        responder(["erro" => $resultado], 500);
      }
      responder(["ok" => true]);
    }

    // SAIR DA FILA
    if ($acao === "sair_fila") {
      $stmt = $pdo->prepare("UPDATE alugueis SET status = 'cancelado', em_andamento = NULL, encerrado_em = NOW()
                             WHERE livro_id = ? AND usuario_id = ? AND status = 'fila'");
      $stmt->execute([$livroId, $usuarioId]);
      if ($stmt->rowCount() === 0) {
        responder(["erro" => "Você não está na fila deste livro."], 400);
      }
      responder(["ok" => true]);
    }

    // DEVOLVER: encerra o aluguel e passa a vez para o próximo da fila
    if ($acao === "devolver") {
      $stmt = $pdo->prepare("UPDATE alugueis SET status = 'devolvido', livro_ativo = NULL, em_andamento = NULL, encerrado_em = NOW()
                             WHERE livro_id = ? AND usuario_id = ? AND status = 'ativo'");
      $stmt->execute([$livroId, $usuarioId]);
      if ($stmt->rowCount() === 0) {
        responder(["erro" => "Você não está com este livro."], 400);
      }
      atualizarAluguel($pdo, $livroId);
      responder(["ok" => true]);
    }

    // ATUALIZAÇÃO AUTOMÁTICA da posição na fila
    responder(["ok" => true] + estadoAluguel($pdo, $livroId, $usuarioId));
  }

  // ---------- MINHA CONTA: nome, senha e e-mail ----------
  if ($acao === "salvar_nome" || $acao === "trocar_senha" || $acao === "pedir_troca_email") {
    if (!isset($_SESSION["usuario_id"])) {
      responder(["erro" => "Faça login."], 401);
    }
    $usuarioId = (int)$_SESSION["usuario_id"];

    // NOME (de exibição): não precisa de confirmação
    if ($acao === "salvar_nome") {
      $nome = preg_replace('/\s+/', ' ', trim(post("nome")));
      if (mb_strlen($nome) > 80) {
        responder(["erro" => "O nome pode ter até 80 caracteres."], 400);
      }
      $stmt = $pdo->prepare("UPDATE usuarios SET nome = ? WHERE id = ?");
      $stmt->execute([$nome !== "" ? $nome : null, $usuarioId]);
      responder([
        "ok"       => true,
        "mensagem" => "Nome salvo!",
        "nome"     => $nome !== "" ? $nome : $_SESSION["usuario"],
      ]);
    }

    // SENHA: pede a senha atual (já está logado, então não precisa de link)
    if ($acao === "trocar_senha") {
      $atual = post("senha_atual");
      $nova  = post("senha");
      $nova2 = post("senha2");

      if (strlen($nova) < 6) {
        responder(["erro" => "A nova senha precisa ter pelo menos 6 caracteres."], 400);
      }
      if ($nova !== $nova2) {
        responder(["erro" => "As senhas novas não coincidem."], 400);
      }
      if (!senhaConfere($pdo, $usuarioId, $atual)) {
        responder(["erro" => "A senha atual está incorreta."], 403);
      }
      if ($nova === $atual) {
        responder(["erro" => "A nova senha precisa ser diferente da atual."], 400);
      }

      $stmt = $pdo->prepare("UPDATE usuarios SET senha_hash = ? WHERE id = ?");
      $stmt->execute([password_hash($nova, PASSWORD_DEFAULT), $usuarioId]);

      // links de redefinição que estavam pendentes deixam de valer
      $stmt = $pdo->prepare("UPDATE redefinicoes SET usado = 1 WHERE usuario_id = ?");
      $stmt->execute([$usuarioId]);

      session_regenerate_id(true);

      // aviso por e-mail (se a conta tiver e-mail e o envio funcionar)
      $stmt = $pdo->prepare("SELECT usuario, email FROM usuarios WHERE id = ?");
      $stmt->execute([$usuarioId]);
      $eu = $stmt->fetch();
      if ($eu && $eu["email"]) {
        enviarEmail(
          $eu["email"],
          $eu["usuario"],
          "Sua senha foi alterada - Bibliotech",
          "<p>Olá, <strong>" . h($eu["usuario"]) . "</strong>!</p>"
          . "<p>A senha da sua conta na Bibliotech acabou de ser alterada.</p>"
          . "<p style=\"color:#666;font-size:13px\">Se não foi você, use &ldquo;Esqueci minha senha&rdquo; na tela de login para criar uma nova.</p>",
          "Olá, " . $eu["usuario"] . "!\n\nA senha da sua conta na Bibliotech acabou de ser alterada.\n"
          . "Se não foi você, use \"Esqueci minha senha\" na tela de login."
        );
      }
      responder(["ok" => true, "mensagem" => "Senha alterada!"]);
    }

    // E-MAIL: confirma pelo NOVO endereço antes de trocar
    if ($acao === "pedir_troca_email") {
      $novo  = strtolower(trim(post("email")));
      $atual = post("senha_atual");

      if (strlen($novo) > 150 || !filter_var($novo, FILTER_VALIDATE_EMAIL)) {
        responder(["erro" => "Informe um e-mail válido."], 400);
      }
      if (!senhaConfere($pdo, $usuarioId, $atual)) {
        responder(["erro" => "A senha atual está incorreta."], 403);
      }

      $stmt = $pdo->prepare("SELECT usuario, email FROM usuarios WHERE id = ?");
      $stmt->execute([$usuarioId]);
      $eu = $stmt->fetch();

      if ($eu["email"] === $novo) {
        responder(["erro" => "Este já é o seu e-mail atual."], 400);
      }

      $stmt = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE email = ?");
      $stmt->execute([$novo]);
      if ((int)$stmt->fetchColumn() > 0) {
        responder(["erro" => "Este e-mail já está em uso por outra conta."], 409);
      }

      // no máximo 3 pedidos por hora
      $stmt = $pdo->prepare("SELECT COUNT(*) FROM mudancas_email
                             WHERE usuario_id = ? AND criado_em > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
      $stmt->execute([$usuarioId]);
      if ((int)$stmt->fetchColumn() >= 3) {
        responder(["erro" => "Muitos pedidos seguidos. Tente de novo daqui a 1 hora."], 429);
      }

      try {
        $token = tokenAleatorio();
      } catch (Exception $ex) {
        responder(["erro" => "Não foi possível gerar o link agora."], 500);
      }

      $stmt = $pdo->prepare("INSERT INTO mudancas_email (usuario_id, novo_email, token_hash, expira_em, criado_em)
                             VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR), NOW())");
      $stmt->execute([$usuarioId, $novo, hash("sha256", $token)]);

      $base = defined("URL_SITE") ? rtrim(URL_SITE, "/") : "";
      $link = $base . "/" . basename($_SERVER["SCRIPT_NAME"]) . "?confirmar_email=" . $token;

      $resultado = enviarEmail(
        $novo,
        $eu["usuario"],
        "Confirme o seu novo e-mail - Bibliotech",
        "<p>Olá, <strong>" . h($eu["usuario"]) . "</strong>!</p>"
        . "<p>Para usar este endereço na sua conta da Bibliotech, confirme clicando no link abaixo "
        . "(entre na sua conta antes, se precisar).</p>"
        . "<p><a href=\"" . h($link) . "\">Confirmar novo e-mail</a></p>"
        . "<p style=\"color:#666;font-size:13px\">O link vale por 1 hora. Se não foi você, ignore este e-mail.</p>",
        "Olá, " . $eu["usuario"] . "!\n\nPara usar este endereço na sua conta da Bibliotech, confirme pelo link "
        . "(entre na sua conta antes, se precisar). Vale por 1 hora:\n" . $link . "\n\nSe não foi você, ignore este e-mail."
      );
      if ($resultado !== true) {
        responder(["erro" => $resultado], 500);
      }

      // avisa o e-mail antigo, para o dono da conta perceber se não foi ele
      if ($eu["email"]) {
        enviarEmail(
          $eu["email"],
          $eu["usuario"],
          "Pedido para trocar o e-mail - Bibliotech",
          "<p>Olá, <strong>" . h($eu["usuario"]) . "</strong>!</p>"
          . "<p>Recebemos um pedido para trocar o e-mail da sua conta para <strong>" . h($novo) . "</strong>. "
          . "A troca só acontece se o novo endereço for confirmado.</p>"
          . "<p style=\"color:#666;font-size:13px\">Se não foi você, troque a sua senha.</p>",
          "Recebemos um pedido para trocar o e-mail da sua conta para " . $novo . ". "
          . "A troca só acontece se o novo endereço for confirmado. Se não foi você, troque a sua senha."
        );
      }

      responder([
        "ok"       => true,
        "mensagem" => "Enviamos um link de confirmação para o novo e-mail. A troca só vale depois que você clicar nele (1 hora).",
      ]);
    }
  }

  // ---------- EDITAR LIVRO (administrador) ----------
  if ($acao === "editar_livro") {
    if (!isset($_SESSION["usuario"])) {
      responder(["erro" => "Faça login."], 401);
    }
    if (!ehAdmin($pdo)) {
      responder(["erro" => "Você não tem permissão para editar livros."], 403);
    }

    $livroId = (int)post("livro_id");
    $stmt = $pdo->prepare("SELECT id, capa, pdf FROM livros WHERE id = ?");
    $stmt->execute([$livroId]);
    $atual = $stmt->fetch();
    if (!$atual) {
      responder(["erro" => "Livro não encontrado."], 404);
    }

    $titulo  = trim(post("titulo"));
    $autor   = preg_replace('/\s+/', ' ', trim(post("autor")));
    $categoriaId = (int)post("categoria_id"); // código escolhido na lista (0 = sem categoria)
    $sinopse = trim(post("sinopse"));

    if ($titulo === "" || mb_strlen($titulo) > 200) {
      responder(["erro" => "Informe o título (até 200 caracteres)."], 400);
    }
    if (mb_strlen($autor) > 150) {
      responder(["erro" => "O autor pode ter até 150 caracteres."], 400);
    }
    // a categoria só pode ser uma das que já estão cadastradas (ou nenhuma); nunca é criada aqui
    if ($categoriaId > 0) {
      $stmt = $pdo->prepare("SELECT COUNT(*) FROM categorias WHERE id = ?");
      $stmt->execute([$categoriaId]);
      if ((int)$stmt->fetchColumn() === 0) {
        responder(["erro" => "Categoria inválida. Escolha uma da lista."], 400);
      }
    } else {
      $categoriaId = null;
    }

    // arquivos novos (opcionais): confere os dois antes de trocar qualquer coisa
    $novoPdf  = null;
    $novaCapa = null;

    if (arquivoEnviado("pdf")) {
      $r = guardarPdf($_FILES["pdf"]);
      if (!$r["ok"]) {
        responder(["erro" => $r["erro"]], 400);
      }
      $novoPdf = $r["nome"];
    }
    if (arquivoEnviado("capa")) {
      $r = guardarCapa($_FILES["capa"]);
      if (!$r["ok"]) {
        apagarArquivoGuardado("pdfs", $novoPdf);
        responder(["erro" => $r["erro"]], 400);
      }
      $novaCapa = $r["caminho"];
    }

    $autorId = acharOuCriarAutor($pdo, $autor);

    $stmt = $pdo->prepare("UPDATE livros SET titulo = ?, autor_id = ?, categoria_id = ?, sinopse = ?, capa = ?, pdf = ? WHERE id = ?");
    $stmt->execute([
      $titulo,
      $autorId,
      $categoriaId,
      $sinopse !== "" ? $sinopse : null,
      $novaCapa !== null ? $novaCapa : $atual["capa"],
      $novoPdf !== null ? $novoPdf : $atual["pdf"],
      $livroId,
    ]);

    // apaga os arquivos antigos que foram trocados (a capa só se estiver na pasta "capas")
    if ($novaCapa !== null && strpos($atual["capa"], "capas/") === 0) {
      apagarArquivoGuardado("capas", $atual["capa"]);
    }
    if ($novoPdf !== null) {
      apagarArquivoGuardado("pdfs", $atual["pdf"]);
    }

    responder(["ok" => true]);
  }

  // ---------- REORDENAR LIVROS ----------
  if ($acao === "reordenar") {
    if (!isset($_SESSION["usuario"])) {
      responder(["erro" => "Faça login."], 401);
    }
    if (!ehAdmin($pdo)) {
      responder(["erro" => "Você não tem permissão para reordenar."], 403);
    }

    $ids = json_decode(post("ids"), true);
    if (!is_array($ids)) {
      responder(["erro" => "Dados inválidos."], 400);
    }

    $stmt = $pdo->prepare("UPDATE livros SET ordem = ? WHERE id = ?");
    foreach ($ids as $posicao => $id) {
      $stmt->execute([$posicao + 1, (int)$id]);
    }
    responder(["ok" => true]);
  }

  responder(["erro" => "Ação inválida."], 400);
}

/* ============================================================
   3b. LEITOR ONLINE (o PDF nunca é entregue como link para baixar)
   ============================================================ */

// Entrega o PDF só para o leitor da Bibliotech (pedido feito pelo JavaScript do leitor).
if (isset($_GET["pdf"])) {
  $info = aluguelLiberado($pdo, (int)$_GET["pdf"]);

  if (!$info || !$info["pdf"] || !isset($_SERVER["HTTP_X_LEITOR"])) {
    http_response_code(403);
    exit("Acesso negado ou aluguel encerrado.");
  }

  $arquivo = pastaPdfs() . "/" . basename($info["pdf"]);
  if (!is_file($arquivo)) {
    http_response_code(404);
    exit("Arquivo não encontrado.");
  }

  session_write_close(); // libera a sessão enquanto o arquivo é enviado
  header("Content-Type: application/pdf");
  header("Content-Disposition: inline");
  header("Content-Length: " . filesize($arquivo));
  header("Cache-Control: private, no-store, max-age=0");
  header("X-Content-Type-Options: nosniff");
  readfile($arquivo);
  exit;
}

// Página "Minha conta".
if (isset($_GET["conta"])) {
  if (!isset($_SESSION["usuario_id"])) {
    header("Location: " . $self);
    exit;
  }
  $stmt = $pdo->prepare("SELECT usuario, nome, email FROM usuarios WHERE id = ?");
  $stmt->execute([(int)$_SESSION["usuario_id"]]);
  $conta = $stmt->fetch();
  if (!$conta) {
    header("Location: " . $self . "?sair=1");
    exit;
  }
  ?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Minha conta - Bibliotech</title>
  <link rel="icon" type="image/png" href="logo.png">
  <link rel="stylesheet" href="style.css?v=<?= versao("style.css") ?>">
</head>
<body>

  <div class="barra-topo">
    <a href="<?= h($self) ?>" class="voltar">&larr; Bibliotech</a>
    <div class="acoes">
      <span class="usuario">Olá, <span id="nome-barra"><?= h($conta["nome"] ? $conta["nome"] : $conta["usuario"]) ?></span></span>
      <a href="?sair=1" class="sair">Sair</a>
    </div>
  </div>

  <main class="conta">
    <h1>Minha conta</h1>
    <p class="texto-ajuda">Usuário de login: <strong><?= h($conta["usuario"]) ?></strong> (não pode ser alterado).</p>

    <form class="login-caixa conta-caixa" id="form-nome">
      <h2>Nome</h2>
      <input type="text" name="nome" maxlength="80" placeholder="Como você quer ser chamado" value="<?= h($conta["nome"]) ?>">
      <p class="erro"></p>
      <p class="msg-ok"></p>
      <button type="submit">Salvar nome</button>
    </form>

    <form class="login-caixa conta-caixa" id="form-email">
      <h2>E-mail</h2>
      <p class="texto-ajuda">E-mail atual: <strong><?= h($conta["email"] ? $conta["email"] : "nenhum cadastrado") ?></strong><br>
        Para trocar, enviamos um link de confirmação para o <strong>novo</strong> endereço. O e-mail só muda depois que você clicar nele.</p>
      <input type="email" name="email" placeholder="Novo e-mail" required autocomplete="email">
      <?php campoSenha("senha_atual", "Sua senha atual", "current-password"); ?>
      <p class="erro"></p>
      <p class="msg-ok"></p>
      <button type="submit">Enviar confirmação</button>
    </form>

    <div class="login-caixa conta-caixa" id="caixa-senha">
      <h2>Senha</h2>
      <p class="texto-ajuda">Para sua segurança, enviamos um código de 6 dígitos para o seu e-mail antes de alterar a senha.</p>
      <button type="button" data-abrir-popup-senha>Alterar senha</button>
    </div>
  </main>

  <?php popupSenhaCodigo(true, mascararEmail($conta["email"])); ?>

  <script src="script.js?v=<?= versao("script.js") ?>"></script>
  <script src="conta.js?v=<?= versao("conta.js") ?>"></script>
  <script src="senha.js?v=<?= versao("senha.js") ?>"></script>
</body>
</html>
<?php
  exit;
}

// Confirmação do novo e-mail (o link que chega no NOVO endereço).
if (isset($_GET["confirmar_email"])) {
  if (!isset($_SESSION["usuario_id"])) {
    $okTroca = false;
    $tituloTroca = "Entre na sua conta";
    $textoTroca = "Para confirmar o novo e-mail, entre na sua conta e depois abra o link do e-mail outra vez.";
  } else {
    $resultado = confirmarTrocaEmail($pdo, (string)$_GET["confirmar_email"], (int)$_SESSION["usuario_id"]);
    $okTroca = ($resultado === true);
    $tituloTroca = $okTroca ? "E-mail alterado!" : "Não foi possível confirmar";
    $textoTroca = $okTroca ? "O novo e-mail já está valendo na sua conta." : $resultado;
  }
  ?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Confirmar e-mail - Bibliotech</title>
  <link rel="icon" type="image/png" href="logo.png">
  <link rel="stylesheet" href="style.css?v=<?= versao("style.css") ?>">
</head>
<body>
  <div class="login-overlay">
    <div class="login-caixa">
      <h2><?= h($tituloTroca) ?></h2>
      <p class="texto-ajuda"><?= h($textoTroca) ?></p>
      <p class="alternar"><a href="<?= h($self) ?><?= $okTroca ? "?conta=1" : "" ?>"><?= $okTroca ? "Ir para Minha conta" : "Voltar para a Bibliotech" ?></a></p>
    </div>
  </div>
</body>
</html>
<?php
  exit;
}

// Página de redefinição de senha (o link que chega por e-mail).
if (isset($_GET["redefinir"])) {
  $tokenUrl = (string)$_GET["redefinir"];
  $registro = buscarRedefinicao($pdo, $tokenUrl);
  ?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Nova senha - Bibliotech</title>
  <link rel="icon" type="image/png" href="logo.png">
  <link rel="stylesheet" href="style.css?v=<?= versao("style.css") ?>">
</head>
<body>

  <div class="login-overlay">
    <?php if ($registro): ?>
      <form class="login-caixa" id="form-redefinir">
        <h2>Nova senha</h2>
        <input type="hidden" name="token" value="<?= h($tokenUrl) ?>">
        <?php campoSenha("senha", "Nova senha (mín. 6 caracteres)", "new-password"); ?>
        <?php campoSenha("senha2", "Confirmar nova senha", "new-password"); ?>
        <p class="erro" id="erro-redefinir"></p>
        <p class="msg-ok" id="msg-redefinir"></p>
        <button type="submit">Salvar nova senha</button>
      </form>
    <?php else: ?>
      <div class="login-caixa">
        <h2>Link inválido</h2>
        <p class="texto-ajuda">Este link expirou ou já foi usado. Peça um novo na tela de login, em &ldquo;Esqueci minha senha&rdquo;.</p>
        <p class="alternar"><a href="<?= h($self) ?>">Voltar para o login</a></p>
      </div>
    <?php endif; ?>
  </div>

  <script src="script.js?v=<?= versao("script.js") ?>"></script>
  <script src="redefinir.js?v=<?= versao("redefinir.js") ?>"></script>
</body>
</html>
<?php
  exit;
}

// Página do leitor.
if (isset($_GET["ler"])) {
  $idLeitura = (int)$_GET["ler"];
  $info = aluguelLiberado($pdo, $idLeitura);

  if (!$info || !$info["pdf"]) {
    if (!isset($_SESSION["usuario_id"])) {
      // sem login: guarda o pedido, mostra o login e, depois de entrar, abre o livro
      $_SESSION["voltar_para"] = "ler=" . $idLeitura;
      header("Location: " . $self);
      exit;
    }

    // com login, mas o acesso não vale: explica por quê, em vez de não abrir nada
    http_response_code(403);
    ?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Acesso indisponível - Bibliotech</title>
  <link rel="icon" type="image/png" href="logo.png">
  <link rel="stylesheet" href="style.css?v=<?= versao("style.css") ?>">
</head>
<body>
  <div class="login-overlay">
    <div class="login-caixa">
      <h2>Acesso indisponível</h2>
      <p class="texto-ajuda">Este link não abriu o livro. Isso acontece quando:</p>
      <p class="texto-ajuda">&bull; o aluguel já terminou ou o livro foi devolvido;<br>
        &bull; o link é de outra conta (entre com a conta que fez o aluguel);<br>
        &bull; o livro ainda não tem PDF.</p>
      <p class="alternar"><a href="<?= h($self) ?>">Voltar para a Bibliotech</a></p>
    </div>
  </div>
</body>
</html>
<?php
    exit;
  }
  ?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($info["titulo"]) ?> - Leitor</title>
  <link rel="icon" type="image/png" href="logo.png">
  <link rel="stylesheet" href="style.css?v=<?= versao("style.css") ?>">
</head>
<body class="leitor" oncontextmenu="return false;">

  <div class="barra-topo">
    <a href="<?= h($self) ?>?id=<?= (int)$info["livro_id"] ?>" class="voltar">&larr; <?= h($info["titulo"]) ?></a>
    <div class="acoes">
      <button type="button" class="botao neutro" id="pg-ant" aria-label="Página anterior">&lsaquo;</button>
      <span class="usuario" id="pg-info">Carregando...</span>
      <button type="button" class="botao neutro" id="pg-prox" aria-label="Próxima página">&rsaquo;</button>
    </div>
  </div>

  <div id="paginas"
       data-url="<?= h($self) ?>?pdf=<?= $idLeitura ?>"
       data-segundos="<?= (int)$info["segundos"] ?>"></div>

  <p class="aviso-leitor">Leitura online: o livro não pode ser baixado e o acesso termina quando o prazo do aluguel acabar.</p>

  <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
  <script src="leitor.js?v=<?= versao("leitor.js") ?>"></script>
</body>
</html>
<?php
  exit;
}

/* ============================================================
   4. DADOS DA PÁGINA
   ============================================================ */
$logado = isset($_SESSION["usuario"]);

// Veio de um link do e-mail (?ler=...) sem estar logado: depois do login, abre o livro pedido.
if ($logado && isset($_SESSION["voltar_para"])) {
  $destino = $_SESSION["voltar_para"];
  unset($_SESSION["voltar_para"]);
  if (preg_match('/^ler=\d+$/', $destino)) {
    header("Location: " . $self . "?" . $destino);
    exit;
  }
}
$admin  = $logado && ehAdmin($pdo);

// nome mostrado na barra: o "nome" da conta ou, se não houver, o usuário de login
$nomeExibicao = "";
if ($logado) {
  $stmt = $pdo->prepare("SELECT nome FROM usuarios WHERE id = ?");
  $stmt->execute([(int)$_SESSION["usuario_id"]]);
  $nomeBanco = $stmt->fetchColumn();
  $nomeExibicao = $nomeBanco ? $nomeBanco : $_SESSION["usuario"];
}
$livro  = null;     // preenchido quando a URL tem ?id=...
$livros = [];
$paragrafos = [];
$autores = [];      // nomes de autores já cadastrados (sugestões no formulário)
$categorias = [];   // categorias cadastradas no banco (código e nome), para a lista de escolha
$autoresMenu = [];  // autores com livros (menu "Autores" da barra)
$categoriasMenu = []; // categorias com livros (menu "Categorias" da barra)
$previa = [];       // só as capas, para aparecerem desfocadas atrás do login

// Quem ainda não entrou vê só algumas CAPAS desfocadas atrás do login:
// sem título, autor, sinopse, link nem PDF. A estante de verdade só vai para quem está logado.
// (fica depois da linha acima de propósito: antes, ela zerava a lista de novo)
if (!$logado) {
  $previa = $pdo->query("SELECT capa FROM livros ORDER BY ordem, id LIMIT 12")->fetchAll(PDO::FETCH_COLUMN);
}
$aluguel = null;    // situação do aluguel (só na página de um livro)
$livroId = 0;

// Só buscamos os livros se a pessoa estiver logada: quem não entrou
// não recebe a estante nem no código-fonte da página.
if ($logado) {
  if (isset($_GET["id"])) {
    $stmt = $pdo->prepare("SELECT l.titulo, l.categoria_id, a.nome AS autor, c.nome AS categoria, l.sinopse, l.capa
                           FROM livros l
                           LEFT JOIN autores a ON a.id = l.autor_id
                           LEFT JOIN categorias c ON c.id = l.categoria_id
                           WHERE l.id = ?");
    $stmt->execute([(int)$_GET["id"]]);
    $livro = $stmt->fetch();

    if (!$livro) {            // id que não existe: volta para a estante
      header("Location: " . $self);
      exit;
    }

    $livroId = (int)$_GET["id"];

    // sugestões de autores para o formulário "Editar livro"
    if ($admin) {
      $autores = $pdo->query("SELECT nome FROM autores ORDER BY nome")->fetchAll(PDO::FETCH_COLUMN);
      $categorias = $pdo->query("SELECT id, nome FROM categorias ORDER BY nome")->fetchAll();
    }
    $aluguel = estadoAluguel($pdo, $livroId, (int)$_SESSION["usuario_id"]);

    // separa a sinopse em parágrafos (linha em branco = novo parágrafo)
    $paragrafos = array_filter(
      preg_split("/\R{2,}/", trim((string)$livro["sinopse"])),
      function ($p) { return trim($p) !== ""; }
    );
  } else {
    $livros = $pdo->query("SELECT l.id, l.titulo, l.autor_id, l.categoria_id, a.nome AS autor, c.nome AS categoria, l.capa
                          FROM livros l
                          LEFT JOIN autores a ON a.id = l.autor_id
                          LEFT JOIN categorias c ON c.id = l.categoria_id
                          ORDER BY l.ordem, l.id")->fetchAll();

    // autores que têm livros, para o menu "Autores" ao lado da pesquisa
    $autoresMenu = $pdo->query("SELECT a.id, a.nome, COUNT(l.id) AS total
                                FROM autores a
                                JOIN livros l ON l.autor_id = a.id
                                GROUP BY a.id, a.nome
                                ORDER BY a.nome")->fetchAll();

    // categorias que têm livros, para o menu "Categorias" ao lado do "Autores"
    $categoriasMenu = $pdo->query("SELECT c.id, c.nome, COUNT(l.id) AS total
                                   FROM categorias c
                                   JOIN livros l ON l.categoria_id = c.id
                                   GROUP BY c.id, c.nome
                                   ORDER BY c.nome")->fetchAll();

    // lista de autores para sugerir no formulário "Adicionar livro"
    if ($admin) {
      $autores = $pdo->query("SELECT nome FROM autores ORDER BY nome")->fetchAll(PDO::FETCH_COLUMN);
      $categorias = $pdo->query("SELECT id, nome FROM categorias ORDER BY nome")->fetchAll();
    }
  }
}
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $livro ? h($livro["titulo"]) . " - Bibliotech" : "Bibliotech" ?></title>
  <link rel="icon" type="image/png" href="logo.png">

  <link rel="stylesheet" href="style.css?v=<?= versao("style.css") ?>">
</head>
<body>

  <!-- BARRA DO TOPO -->
  <div class="barra-topo">
    <?php if ($livro): ?>
      <a href="<?= h($self) ?>" class="voltar">&larr; Bibliotech</a>
    <?php else: ?>
      <span>Bibliotech</span>
    <?php endif; ?>

    <?php if ($logado): ?>
      <div class="acoes">
        <?php if (!$livro): ?>
          <?php if ($autoresMenu): ?>
            <div class="menu-autores">
              <button type="button" class="botao neutro btn-autores" id="btn-autores"
                      aria-haspopup="true" aria-expanded="false" title="Filtrar por autor">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                  <circle cx="9" cy="8" r="3.5"/>
                  <path d="M2.5 20c0-3.6 2.9-6 6.5-6s6.5 2.4 6.5 6"/>
                  <path d="M16 4.7a3.5 3.5 0 0 1 0 6.6M18 14.4c2.1.7 3.5 2.6 3.5 5.6"/>
                </svg>
                <span class="rotulo">Autores</span>
              </button>

              <div class="painel-autores oculto" id="painel-autores">
                <button type="button" class="autor-item ativo" data-autor="0" data-nome="">
                  <span>Todos os autores</span>
                </button>
                <?php foreach ($autoresMenu as $a): ?>
                  <button type="button" class="autor-item" data-autor="<?= (int)$a["id"] ?>" data-nome="<?= h($a["nome"]) ?>">
                    <span><?= h($a["nome"]) ?></span>
                    <span class="qtd"><?= (int)$a["total"] ?></span>
                  </button>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endif; ?>

          <?php if ($categoriasMenu): ?>
            <div class="menu-autores">
              <button type="button" class="botao neutro btn-autores" id="btn-categorias"
                      aria-haspopup="true" aria-expanded="false" title="Filtrar por categoria">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                  <path d="M20.6 13.4l-7.2 7.2a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8z"/>
                  <circle cx="7.5" cy="7.5" r="1.2"/>
                </svg>
                <span class="rotulo">Categorias</span>
              </button>

              <div class="painel-autores oculto" id="painel-categorias">
                <button type="button" class="autor-item ativo" data-categoria="0" data-nome="">
                  <span>Todas as categorias</span>
                </button>
                <?php foreach ($categoriasMenu as $cat): ?>
                  <button type="button" class="autor-item" data-categoria="<?= (int)$cat["id"] ?>" data-nome="<?= h($cat["nome"]) ?>">
                    <span><?= h($cat["nome"]) ?></span>
                    <span class="qtd"><?= (int)$cat["total"] ?></span>
                  </button>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endif; ?>

          <?php if ($livros): ?>
            <button type="button" class="botao neutro btn-az" id="btn-az" aria-pressed="false"
                    title="Ordem alfabética (clique de novo para voltar à ordem da estante)">A&ndash;Z</button>
          <?php endif; ?>

          <label class="campo-busca">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none"
                 stroke="currentColor" stroke-width="2"
                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <circle cx="11" cy="11" r="7"/>
              <line x1="21" y1="21" x2="16.65" y2="16.65"/>
            </svg>
            <input type="search" id="busca" class="busca" placeholder="Pesquisar livro...">
          </label>
          <?php if ($admin): ?>
            <button type="button" id="abrir-modal" class="botao">+ Livro</button>
          <?php endif; ?>
        <?php endif; ?>
        <a href="?conta=1" class="usuario" title="Minha conta">Olá, <span id="nome-barra"><?= h($nomeExibicao) ?></span></a>
        <?php if ($admin): ?><span class="selo-admin">Admin</span><?php endif; ?>
        <a href="?sair=1" class="sair">Sair</a>
      </div>
    <?php endif; ?>
  </div>

  <?php if (!$logado): ?>

    <!-- CAPAS DESFOCADAS ATRÁS DO LOGIN (só enfeite: sem links nem dados dos livros) -->
    <?php if ($previa): ?>
      <div class="estante estante-previa" aria-hidden="true">
        <?php foreach ($previa as $capaPrevia): ?>
          <span class="capa"><img src="<?= h($capaPrevia) ?>" alt=""></span>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <!-- POP-UP DE LOGIN / CADASTRO (só existe no HTML para quem não entrou) -->
    <div class="login-overlay" id="login">

      <form class="login-caixa" id="form-login">
        <h2>Entrar</h2>
        <input type="text" name="usuario" placeholder="Usuário" required autocomplete="username">
        <?php campoSenha("senha", "Senha", "current-password"); ?>
        <p class="erro" id="erro-login"></p>
        <p class="msg-ok" id="msg-ok"></p>
        <button type="submit">Entrar</button>
        <p class="alternar">Não tem conta? <a id="ir-cadastro">Cadastre-se</a></p>
        <p class="alternar"><a data-abrir-popup-senha>Esqueci minha senha</a></p>
        <p class="alternar"><a id="ir-admin">Acesso de administrador</a></p>
      </form>

      <form class="login-caixa oculto" id="form-cadastro">
        <h2>Criar conta</h2>
        <input type="text" name="usuario" placeholder="Usuário (letras, números e _)" required autocomplete="username">
        <input type="email" name="email" placeholder="E-mail (você receberá o acesso aos livros)" required autocomplete="email">
        <?php campoSenha("senha", "Senha (mín. 6 caracteres)", "new-password"); ?>
        <?php campoSenha("senha2", "Confirmar senha", "new-password"); ?>
        <p class="erro" id="erro-cad"></p>
        <button type="submit">Cadastrar</button>
        <p class="alternar">Já tem conta? <a id="ir-login">Entrar</a></p>
      </form>

      <form class="login-caixa admin oculto" id="form-admin">
        <h2>Administrador</h2>
        <input type="text" name="usuario" placeholder="Usuário administrador" required autocomplete="username">
        <?php campoSenha("senha", "Senha", "current-password"); ?>
        <p class="erro" id="erro-admin"></p>
        <button type="submit">Entrar como administrador</button>
        <p class="alternar"><a id="voltar-login">&larr; Voltar ao login comum</a></p>
      </form>

    </div>

    <?php popupSenhaCodigo(false); ?>

  <?php elseif ($livro): ?>

    <!-- PÁGINA DE UM LIVRO -->
    <main class="pagina-livro">
      <img class="capa-grande" src="<?= h($livro["capa"]) ?>" alt="<?= h($livro["titulo"]) ?>">

      <div class="detalhes">
        <h1><?= h($livro["titulo"]) ?></h1>
        <?php if ($livro["autor"]): ?>
          <p class="autor"><?= h($livro["autor"]) ?></p>
        <?php endif; ?>
        <?php if ($admin): ?>
          <p><button type="button" class="botao neutro" id="abrir-editar">Editar livro</button></p>
        <?php endif; ?>

        <div class="sinopse">
          <div class="sinopse-topo">
            <h2>Sinopse</h2>
            <?php if ($livro["categoria"]): ?>
              <span class="selo-categoria">Categoria: <strong><?= h($livro["categoria"]) ?></strong></span>
            <?php endif; ?>
          </div>
          <?php if ($paragrafos): ?>
            <?php foreach ($paragrafos as $p): ?>
              <p><?= nl2br(h($p)) ?></p>
            <?php endforeach; ?>
          <?php else: ?>
            <p>Sem sinopse cadastrada.</p>
          <?php endif; ?>
        </div>

        <!-- ALUGUEL -->
        <div class="aluguel" id="aluguel"
             data-livro="<?= (int)$livroId ?>"
             data-status="<?= h($aluguel["meu_status"]) ?>"
             data-pos="<?= (int)$aluguel["pos"] ?>">
          <h2>Aluguel</h2>

          <?php if ($aluguel["meu_status"] === "ativo"): ?>
            <p>É a sua vez! Você está com este livro até <strong><?= h($aluguel["expira_txt"]) ?></strong>
               (restam <?= h($aluguel["restante"]) ?>).</p>
            <div class="barra-fila"><div class="barra-fila-preench" style="width: 100%"></div></div>

            <?php if ($aluguel["tem_pdf"]): ?>
              <p><a class="botao" href="?ler=<?= (int)$aluguel["meu_id"] ?>">Ler agora</a></p>
              <p class="aluguel-info">
                <?php if ($aluguel["email_enviado"]): ?>Também enviamos o acesso para o seu e-mail. <?php endif; ?>
                <a href="#" data-aluguel="reenviar_acesso">Reenviar e-mail de acesso</a>
              </p>
            <?php else: ?>
              <p class="aluguel-info">O PDF deste livro ainda não foi enviado pelo administrador.</p>
            <?php endif; ?>

            <button type="button" class="botao" data-aluguel="devolver">Devolver o livro</button>

          <?php elseif ($aluguel["meu_status"] === "fila"): ?>
            <p>Você está na posição <strong id="fila-pos"><?= (int)$aluguel["pos"] ?></strong>
               de <strong id="fila-total"><?= (int)$aluguel["fila_total"] ?></strong> na fila.</p>
            <div class="barra-fila">
              <div class="barra-fila-preench" style="width: <?= (int)$aluguel["pct"] ?>%"></div>
            </div>
            <p class="aluguel-info">O livro está alugado até <?= h($aluguel["expira_txt"]) ?>.
               Previsão máxima para a sua vez: <span id="fila-previsao"><?= h($aluguel["previsao_txt"]) ?></span>.</p>
            <button type="button" class="botao neutro" data-aluguel="sair_fila">Sair da fila</button>

          <?php elseif ($aluguel["ativo"]): ?>
            <p>Este livro está alugado até <strong><?= h($aluguel["expira_txt"]) ?></strong>.
              <?php if ($aluguel["fila_total"] > 0): ?>
                Há <?= (int)$aluguel["fila_total"] ?> pessoa<?= $aluguel["fila_total"] == 1 ? "" : "s" ?> na fila.
              <?php else: ?>
                Ninguém está na fila.
              <?php endif; ?>
            </p>
            <button type="button" class="botao" data-aluguel="alugar">Entrar na fila</button>

          <?php else: ?>
            <p>Disponível! O aluguel dura no máximo <?= (int)DIAS_ALUGUEL ?> dias.</p>
            <button type="button" class="botao" data-aluguel="alugar">Pedir aluguel</button>
          <?php endif; ?>

          <p class="erro" id="erro-aluguel"></p>
          <p class="msg-ok" id="msg-aluguel"></p>
        </div>

        <?php if ($admin): ?>
          <!-- EDITAR LIVRO (só administradores) -->
          <div class="login-overlay oculto" id="modal-editar">
            <form class="login-caixa" id="form-editar">
              <h2>Editar livro</h2>
              <input type="hidden" name="livro_id" value="<?= (int)$livroId ?>">

              <label for="ed-titulo">Título</label>
              <input type="text" id="ed-titulo" name="titulo" maxlength="200" required value="<?= h($livro["titulo"]) ?>">

              <label for="ed-autor">Autor</label>
              <input type="text" id="ed-autor" name="autor" list="lista-autores-edicao" maxlength="150"
                     autocomplete="off" placeholder="Escolha ou digite um novo" value="<?= h($livro["autor"]) ?>">
              <datalist id="lista-autores-edicao">
                <?php foreach ($autores as $nomeAutor): ?>
                  <option value="<?= h($nomeAutor) ?>">
                <?php endforeach; ?>
              </datalist>

              <label for="ed-categoria">Categoria</label>
              <select id="ed-categoria" name="categoria_id">
                <option value="">Sem categoria</option>
                <?php foreach ($categorias as $cat): ?>
                  <option value="<?= (int)$cat["id"] ?>"<?= (int)$livro["categoria_id"] === (int)$cat["id"] ? " selected" : "" ?>><?= h($cat["nome"]) ?></option>
                <?php endforeach; ?>
              </select>

              <label for="ed-sinopse">Sinopse</label>
              <textarea id="ed-sinopse" name="sinopse" placeholder="Linha em branco = novo parágrafo"><?= h($livro["sinopse"]) ?></textarea>

              <label for="ed-capa">Trocar a capa (opcional: JPG ou PNG, até 3 MB)</label>
              <input type="file" id="ed-capa" name="capa" accept="image/jpeg,image/png">

              <label for="ed-pdf"><?= $aluguel["tem_pdf"] ? "Trocar o PDF (opcional)" : "PDF do livro (opcional)" ?></label>
              <input type="file" id="ed-pdf" name="pdf" accept="application/pdf">

              <p class="erro" id="erro-editar"></p>
              <button type="submit">Salvar alterações</button>
              <button type="button" class="secundario" id="cancelar-editar">Cancelar</button>
            </form>
          </div>
        <?php endif; ?>
      </div>
    </main>

  <?php else: ?>

    <?php if ($admin): ?>
    <!-- POP-UP PARA ADICIONAR LIVRO (só administradores) -->
    <div class="login-overlay oculto" id="modal-livro">
      <form class="login-caixa" id="form-livro">
        <h2>Adicionar livro</h2>

        <input type="text" name="titulo" placeholder="Título" maxlength="200" required>
        <input type="text" name="autor" list="lista-autores" placeholder="Autor (escolha ou digite um novo)" maxlength="150" autocomplete="off">
        <datalist id="lista-autores">
          <?php foreach ($autores as $nomeAutor): ?>
            <option value="<?= h($nomeAutor) ?>">
          <?php endforeach; ?>
        </datalist>
        <select name="categoria_id" aria-label="Categoria">
          <option value="">Sem categoria</option>
          <?php foreach ($categorias as $cat): ?>
            <option value="<?= (int)$cat["id"] ?>"><?= h($cat["nome"]) ?></option>
          <?php endforeach; ?>
        </select>
        <textarea name="sinopse" placeholder="Sinopse (linha em branco = novo parágrafo)"></textarea>

        <label for="capa">Capa (JPG ou PNG, até 3 MB)</label>
        <input type="file" name="capa" id="capa" accept="image/jpeg,image/png" required>

        <label for="pdf-novo">PDF do livro (opcional)</label>
        <input type="file" name="pdf" id="pdf-novo" accept="application/pdf">

        <p class="erro" id="erro-livro"></p>
        <button type="submit">Adicionar</button>
        <button type="button" class="secundario" id="cancelar-livro">Cancelar</button>
      </form>
    </div>
    <?php endif; ?>

    <!-- ESTANTE DE LIVROS (vem do banco de dados) -->
    <?php if ($livros): ?>
      <?php if ($admin && count($livros) > 1): ?>
        <p class="dica" id="dica-ordem">Arraste as capas para mudar a ordem da estante.</p>
      <?php endif; ?>
      <p class="filtro-info oculto" id="filtro-info">
        Filtrando por <strong id="filtro-info-nome"></strong> &middot; <a id="limpar-filtro">limpar filtros</a>
      </p>
      <div class="estante<?= $admin ? ' editavel' : '' ?>" id="estante">
        <?php foreach ($livros as $l): ?>
          <a href="?id=<?= (int)$l["id"] ?>" class="capa" data-id="<?= (int)$l["id"] ?>" data-autor="<?= (int)$l["autor_id"] ?>" data-categoria="<?= (int)$l["categoria_id"] ?>" data-nome="<?= h($l["titulo"]) ?>"
             data-titulo="<?= h($l["titulo"] . " " . $l["autor"] . " " . $l["categoria"]) ?>">
            <img src="<?= h($l["capa"]) ?>" alt="<?= h($l["titulo"]) ?>">
          </a>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <p class="vazio">Nenhum livro cadastrado ainda.<?= $admin ? ' Clique em "+ Livro" para adicionar o primeiro.' : '' ?></p>
    <?php endif; ?>

    <p id="sem-resultado">Nenhum livro encontrado.</p>

  <?php endif; ?>

  <script src="script.js?v=<?= versao("script.js") ?>"></script>
  <script src="senha.js?v=<?= versao("senha.js") ?>"></script>

</body>
</html>
