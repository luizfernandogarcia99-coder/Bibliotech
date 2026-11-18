-- Migra o banco que você JÁ TEM para a tabela de autores.
-- Rode no phpMyAdmin, na aba SQL, UMA vez só. Os livros e os autores atuais são preservados.

USE bibliotech;

-- 1. Tabela de autores
CREATE TABLE IF NOT EXISTS autores (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(150) NOT NULL,
  UNIQUE KEY uq_autor_nome (nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Copia os nomes que já estão escritos nos livros (sem repetir)
INSERT IGNORE INTO autores (nome)
SELECT DISTINCT CONVERT(TRIM(autor) USING utf8mb4) COLLATE utf8mb4_unicode_ci
FROM livros
WHERE autor IS NOT NULL AND TRIM(autor) <> '';

-- 3. Coluna que liga o livro ao autor
ALTER TABLE livros ADD COLUMN autor_id INT NULL;

-- 4. Preenche a ligação de cada livro
UPDATE livros l
JOIN autores a ON a.nome = CONVERT(TRIM(l.autor) USING utf8mb4) COLLATE utf8mb4_unicode_ci
SET l.autor_id = a.id;

-- 5. Converte livros para InnoDB (só ele aplica chaves estrangeiras) e cria a chave
ALTER TABLE livros ENGINE=InnoDB;

ALTER TABLE livros
  ADD CONSTRAINT fk_livro_autor FOREIGN KEY (autor_id) REFERENCES autores(id)
  ON DELETE SET NULL ON UPDATE CASCADE;

-- Confira o resultado (cada livro deve mostrar o nome do autor na terceira coluna):
-- SELECT l.titulo, l.autor, a.nome FROM livros l LEFT JOIN autores a ON a.id = l.autor_id;

-- Só depois de conferir, se quiser, apague a coluna antiga:
-- ALTER TABLE livros DROP COLUMN autor;
