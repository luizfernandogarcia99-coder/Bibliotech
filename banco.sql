-- BIBLIOTECH - estrutura completa do banco (instalação nova).
-- Rode no phpMyAdmin, na aba SQL.
-- Quem já tem o banco criado deve rodar o atualizar_autores.sql no lugar deste.

CREATE DATABASE IF NOT EXISTS bibliotech
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE bibliotech;

CREATE TABLE IF NOT EXISTS usuarios (
  id INT AUTO_INCREMENT PRIMARY KEY,
  usuario VARCHAR(30) NOT NULL UNIQUE,
  nome VARCHAR(80) NULL,                       -- nome de exibição (opcional)
  email VARCHAR(150) NULL UNIQUE,
  senha_hash VARCHAR(255) NOT NULL,
  admin TINYINT(1) NOT NULL DEFAULT 0,         -- 1 = administrador
  criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS autores (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(150) NOT NULL,
  UNIQUE KEY uq_autor_nome (nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS categorias (
  id INT AUTO_INCREMENT PRIMARY KEY,           -- o "código" da categoria
  nome VARCHAR(80) NOT NULL,
  UNIQUE KEY uq_categoria_nome (nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS livros (
  id INT AUTO_INCREMENT PRIMARY KEY,
  titulo VARCHAR(200) NOT NULL,
  autor_id INT NULL,                           -- liga o livro ao autor (tabela autores)
  categoria_id INT NULL,                       -- liga o livro à categoria (tabela categorias)
  sinopse TEXT,
  capa VARCHAR(255) NOT NULL,
  pdf VARCHAR(255) NULL,                       -- nome do arquivo na pasta protegida "pdfs"
  ordem INT NOT NULL DEFAULT 0,                -- posição na estante (o administrador arrasta)
  criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_livro_autor FOREIGN KEY (autor_id) REFERENCES autores(id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_livro_categoria FOREIGN KEY (categoria_id) REFERENCES categorias(id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Aluguel de livros (prazo de 3 dias) e fila de espera.
-- status: 'fila', 'ativo', 'devolvido', 'expirado' ou 'cancelado'
CREATE TABLE IF NOT EXISTS alugueis (
  id INT AUTO_INCREMENT PRIMARY KEY,
  livro_id INT NOT NULL,
  usuario_id INT NOT NULL,
  status VARCHAR(10) NOT NULL,
  pos_entrada INT NOT NULL DEFAULT 1,          -- posição na fila quando entrou (barra verde)
  criado_em DATETIME NOT NULL,
  inicio DATETIME NULL,
  expira_em DATETIME NULL,
  encerrado_em DATETIME NULL,
  livro_ativo INT NULL,                        -- = livro_id enquanto ativo: 1 aluguel ativo por livro
  em_andamento TINYINT NULL,                   -- = 1 enquanto na fila/ativo: impede pedido duplicado
  email_enviado TINYINT NOT NULL DEFAULT 0,
  UNIQUE KEY uq_livro_ativo (livro_ativo),
  UNIQUE KEY uq_pedido (usuario_id, livro_id, em_andamento),
  KEY idx_livro_status (livro_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Links de redefinição de senha (valem 1 hora). Só o hash do código é guardado.
CREATE TABLE IF NOT EXISTS redefinicoes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expira_em DATETIME NOT NULL,
  usado TINYINT NOT NULL DEFAULT 0,
  criado_em DATETIME NOT NULL,
  UNIQUE KEY uq_token (token_hash),
  KEY idx_usuario (usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pedidos de troca de e-mail (confirmados por link enviado ao novo endereço).
CREATE TABLE IF NOT EXISTS mudancas_email (
  id INT AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT NOT NULL,
  novo_email VARCHAR(150) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expira_em DATETIME NOT NULL,
  usado TINYINT NOT NULL DEFAULT 0,
  criado_em DATETIME NOT NULL,
  UNIQUE KEY uq_token_email (token_hash),
  KEY idx_usuario_email (usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Códigos de 6 dígitos para alterar a senha (10 minutos, no máximo 5 tentativas).
CREATE TABLE IF NOT EXISTS codigos_senha (
  id INT AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT NOT NULL,
  codigo_hash CHAR(64) NOT NULL,
  expira_em DATETIME NOT NULL,
  tentativas TINYINT NOT NULL DEFAULT 0,
  usado TINYINT NOT NULL DEFAULT 0,
  criado_em DATETIME NOT NULL,
  KEY idx_usuario_codigo (usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Categorias iniciais. O site NÃO cria categorias: a lista de escolha usa só o que está nesta tabela.
-- Para acrescentar mais:  INSERT INTO categorias (nome) VALUES ('Nome da categoria');
INSERT INTO categorias (nome) VALUES
  ('Fantasia'), ('Aventura'), ('Romance'), ('Terror'), ('Ficção científica'), ('Drama'), ('Mistério');

-- Livros de exemplo (opcional, para ver a estante cheia).
-- Descomente SÓ se você tiver as imagens das capas na mesma pasta dos arquivos PHP:
-- elas não estão no repositório porque têm direitos autorais.
-- INSERT INTO autores (nome) VALUES ('Rick Riordan');
-- INSERT INTO livros (titulo, autor_id, categoria_id, capa, ordem) VALUES
--   ('O Ladrão de Raios', (SELECT id FROM autores WHERE nome = 'Rick Riordan'),
--    (SELECT id FROM categorias WHERE nome = 'Fantasia'), 'ladraoderaios.jpg', 1);
