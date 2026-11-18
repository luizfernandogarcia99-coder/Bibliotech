-- Acrescenta as categorias ao banco que você JÁ TEM.
-- Rode no phpMyAdmin (aba SQL), com o banco bibliotech selecionado, UMA vez só.

USE bibliotech;

-- 1. Tabela de categorias: cada uma tem um código (id) e um nome
CREATE TABLE IF NOT EXISTS categorias (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(80) NOT NULL,
  UNIQUE KEY uq_categoria_nome (nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. O livro guarda o CÓDIGO da categoria
ALTER TABLE livros ADD COLUMN categoria_id INT NULL;

-- Pronto: o site já funciona. Os livros antigos ficam sem categoria até você
-- abrir cada um e usar "Editar livro".

-- 3. OPCIONAL: chave estrangeira (o banco passa a impedir códigos que não existem).
--    Rode só depois de conferir que os passos 1 e 2 deram certo.
-- ALTER TABLE livros ENGINE=InnoDB;
-- ALTER TABLE livros
--   ADD CONSTRAINT fk_livro_categoria FOREIGN KEY (categoria_id) REFERENCES categorias(id)
--   ON DELETE SET NULL ON UPDATE CASCADE;

-- 4. As categorias só existem se estiverem nesta tabela: o site NÃO cria categorias.
--    Para cadastrar (ou acrescentar mais), rode, por exemplo:
-- INSERT INTO categorias (nome) VALUES ('Fantasia'), ('Romance'), ('Terror'), ('Aventura'), ('Ficção científica');
--    Para corrigir um nome:  UPDATE categorias SET nome = 'Novo nome' WHERE id = 1;
