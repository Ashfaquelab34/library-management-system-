CREATE DATABASE IF NOT EXISTS library_management_system_2026 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE library_management_system_2026;

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  phone VARCHAR(30) NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('student','assistant') NOT NULL DEFAULT 'student',
  student_id VARCHAR(30) NULL UNIQUE,
  librarian_id VARCHAR(30) NULL UNIQUE,
  member_type ENUM('Student','Faculty','Other') NOT NULL DEFAULT 'Student',
  address VARCHAR(255) NULL,
  status ENUM('active','blocked','deleted') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_users_role_status(role,status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS authors (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL UNIQUE,
  bio TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS categories (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE,
  description VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS books (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  isbn VARCHAR(30) NULL UNIQUE,
  title VARCHAR(220) NOT NULL,
  author_id BIGINT UNSIGNED NOT NULL,
  category_id BIGINT UNSIGNED NOT NULL,
  publisher VARCHAR(150) NULL,
  published_year SMALLINT UNSIGNED NULL,
  description TEXT NULL,
  cover_url VARCHAR(500) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_books_author FOREIGN KEY(author_id) REFERENCES authors(id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_books_category FOREIGN KEY(category_id) REFERENCES categories(id) ON UPDATE CASCADE ON DELETE RESTRICT,
  INDEX idx_books_title(title), INDEX idx_books_author(author_id), INDEX idx_books_category(category_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS book_copies (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  book_id BIGINT UNSIGNED NOT NULL,
  accession_no VARCHAR(40) NOT NULL UNIQUE,
  barcode VARCHAR(80) NULL UNIQUE,
  status ENUM('available','issued','reserved','maintenance','lost') NOT NULL DEFAULT 'available',
  shelf_location VARCHAR(80) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_copies_book FOREIGN KEY(book_id) REFERENCES books(id) ON UPDATE CASCADE ON DELETE CASCADE,
  INDEX idx_copies_book_status(book_id,status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS issues (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  copy_id BIGINT UNSIGNED NOT NULL,
  student_id BIGINT UNSIGNED NOT NULL,
  issued_by BIGINT UNSIGNED NOT NULL,
  issue_date DATE NOT NULL,
  due_date DATE NOT NULL,
  fine_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
  returned_at DATETIME NULL,
  status ENUM('issued','returned','overdue') NOT NULL DEFAULT 'issued',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_issue_copy FOREIGN KEY(copy_id) REFERENCES book_copies(id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_issue_student FOREIGN KEY(student_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_issue_assistant FOREIGN KEY(issued_by) REFERENCES users(id) ON UPDATE CASCADE ON DELETE RESTRICT,
  INDEX idx_issues_student_status(student_id,status), INDEX idx_issues_due(due_date,status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS returns_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  issue_id BIGINT UNSIGNED NOT NULL UNIQUE,
  received_by BIGINT UNSIGNED NOT NULL,
  returned_at DATETIME NOT NULL,
  overdue_days INT UNSIGNED NOT NULL DEFAULT 0,
  fine_paid DECIMAL(10,2) NOT NULL DEFAULT 0,
  remarks VARCHAR(255) NULL,
  condition_note VARCHAR(255) NULL,
  CONSTRAINT fk_return_issue FOREIGN KEY(issue_id) REFERENCES issues(id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_return_assistant FOREIGN KEY(received_by) REFERENCES users(id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS fines (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  issue_id BIGINT UNSIGNED NOT NULL UNIQUE,
  student_id BIGINT UNSIGNED NOT NULL,
  amount DECIMAL(10,2) NOT NULL DEFAULT 0,
  reason VARCHAR(255) NULL,
  status ENUM('unpaid','paid','waived') NOT NULL DEFAULT 'unpaid',
  paid_at DATETIME NULL,
  paid_date DATETIME NULL,
  handled_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_fine_issue FOREIGN KEY(issue_id) REFERENCES issues(id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_fine_student FOREIGN KEY(student_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_fine_handler FOREIGN KEY(handled_by) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
  INDEX idx_fines_student_status(student_id,status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS reservations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  book_id BIGINT UNSIGNED NOT NULL,
  student_id BIGINT UNSIGNED NOT NULL,
  status ENUM('waiting','approved','rejected','fulfilled','cancelled') NOT NULL DEFAULT 'waiting',
  reserved_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  request_date DATE NULL,
  expiry_date DATE NULL,
  fulfilled_at DATETIME NULL,
  CONSTRAINT fk_res_book FOREIGN KEY(book_id) REFERENCES books(id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_res_student FOREIGN KEY(student_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE CASCADE,
  INDEX idx_res_book_status(book_id,status), INDEX idx_res_student(student_id,status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL,
  action VARCHAR(80) NOT NULL,
  entity VARCHAR(80) NOT NULL,
  entity_id BIGINT UNSIGNED NULL,
  details TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_audit_user FOREIGN KEY(user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
  INDEX idx_audit_created(created_at), INDEX idx_audit_user(user_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS library_settings (
  id TINYINT UNSIGNED PRIMARY KEY,
  loan_days INT UNSIGNED NOT NULL DEFAULT 14,
  fine_per_day DECIMAL(10,2) NOT NULL DEFAULT 5.00,
  library_name VARCHAR(180) NOT NULL DEFAULT 'Library Management System',
  librarian_name VARCHAR(120) NULL,
  email VARCHAR(190) NULL,
  phone VARCHAR(40) NULL,
  address VARCHAR(255) NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;
INSERT IGNORE INTO library_settings(id) VALUES(1);


-- Starter catalog: 20 real, widely used books for a fresh installation.
-- These rows are inserted only when the title/author/category does not already exist.
INSERT IGNORE INTO authors(name,bio) VALUES
('J.K. Rowling','British author best known for the Harry Potter series.'),
('George Orwell','English novelist and essayist known for Nineteen Eighty-Four and Animal Farm.'),
('Jane Austen','English novelist known for Pride and Prejudice and other classic novels.'),
('Harper Lee','American novelist best known for To Kill a Mockingbird.'),
('F. Scott Fitzgerald','American novelist and short-story writer of the Jazz Age.'),
('Leo Tolstoy','Russian novelist and author of War and Peace and Anna Karenina.'),
('Paulo Coelho','Brazilian novelist best known for The Alchemist.'),
('Yuval Noah Harari','Israeli historian and author of Sapiens.'),
('Stephen Hawking','English theoretical physicist and science communicator.'),
('R. K. Narayan','Indian English-language novelist known for the fictional town of Malgudi.'),
('A. P. J. Abdul Kalam','Indian aerospace scientist and author.'),
('Robert C. Martin','American software engineer and author of Clean Code.'),
('Martin Fowler','British software developer and author on software architecture and refactoring.'),
('Thomas H. Cormen','Computer scientist and co-author of Introduction to Algorithms.'),
('E. Balagurusamy','Indian computer science educator and author of programming textbooks.'),
('Donald E. Knuth','American computer scientist and author of The Art of Computer Programming.'),
('James Clear','American author known for Atomic Habits.'),
('Daniel Kahneman','Israeli-American psychologist and Nobel Prize-winning author.'),
('J. D. Salinger','American writer best known for The Catcher in the Rye.'),
('Charles Dickens','English novelist and social critic.'),
('Agatha Christie','English writer known for detective fiction featuring Hercule Poirot and Miss Marple.');

INSERT IGNORE INTO categories(name,description) VALUES
('Fiction','Novels and literary works.'),
('Classic Literature','Enduring works of world literature.'),
('Science','Science, physics and popular science.'),
('History','History, civilization and historical analysis.'),
('Indian Literature','Indian authors and literature.'),
('Biography & Inspiration','Life stories, leadership and inspirational works.'),
('Computer Science','Programming, algorithms and software engineering.'),
('Self Development','Habits, learning and personal development.'),
('Psychology','Human behaviour, decision-making and psychology.');

INSERT IGNORE INTO books(isbn,title,author_id,category_id,publisher,published_year,description)
SELECT x.isbn,x.title,a.id,c.id,x.publisher,x.published_year,x.description
FROM (
  SELECT '9780545010221' isbn,'Harry Potter and the Philosopher''s Stone' title,'J.K. Rowling' author,'Fiction' category,'Bloomsbury' publisher,1997 published_year,'The first novel in the Harry Potter series.' description
  UNION ALL SELECT '9780451524935','Nineteen Eighty-Four','George Orwell','Classic Literature','Signet Classics',1949,'A dystopian novel about surveillance, power and truth.'
  UNION ALL SELECT '9780141439518','Pride and Prejudice','Jane Austen','Classic Literature','Penguin Classics',1813,'A classic novel of manners, relationships and social class.'
  UNION ALL SELECT '9780061120084','To Kill a Mockingbird','Harper Lee','Classic Literature','Harper Perennial',1960,'A novel about justice, morality and childhood in the American South.'
  UNION ALL SELECT '9780743273565','The Great Gatsby','F. Scott Fitzgerald','Classic Literature','Scribner',1925,'A classic portrait of the Jazz Age and the American dream.'
  UNION ALL SELECT '9780199232765','War and Peace','Leo Tolstoy','Classic Literature','Oxford University Press',1869,'An epic novel set during the Napoleonic wars.'
  UNION ALL SELECT '9780062315007','The Alchemist','Paulo Coelho','Fiction','HarperOne',1988,'A philosophical novel about dreams, purpose and personal destiny.'
  UNION ALL SELECT '9780062316097','Sapiens: A Brief History of Humankind','Yuval Noah Harari','History','Harper',2015,'A broad history of Homo sapiens and human civilization.'
  UNION ALL SELECT '9780553380163','A Brief History of Time','Stephen Hawking','Science','Bantam',1988,'An accessible introduction to cosmology and modern physics.'
  UNION ALL SELECT '9788185986177','Malgudi Days','R. K. Narayan','Indian Literature','Indian Thought Publications',1943,'Short stories set in the fictional South Indian town of Malgudi.'
  UNION ALL SELECT '9788173711466','Wings of Fire','A. P. J. Abdul Kalam','Biography & Inspiration','Universities Press',1999,'An autobiography of A. P. J. Abdul Kalam.'
  UNION ALL SELECT '9780132350884','Clean Code','Robert C. Martin','Computer Science','Prentice Hall',2008,'A practical guide to writing readable, maintainable software.'
  UNION ALL SELECT '9780134757599','Refactoring: Improving the Design of Existing Code','Martin Fowler','Computer Science','Addison-Wesley Professional',2018,'Techniques for improving existing code design safely.'
  UNION ALL SELECT '9780262033848','Introduction to Algorithms','Thomas H. Cormen','Computer Science','MIT Press',2009,'A comprehensive textbook covering fundamental algorithms.'
  UNION ALL SELECT '9789352533133','Programming in ANSI C','E. Balagurusamy','Computer Science','McGraw Hill Education',2019,'A textbook covering C programming fundamentals and practice.'
  UNION ALL SELECT '9780201896831','The Art of Computer Programming, Vol. 1','Donald E. Knuth','Computer Science','Addison-Wesley',1997,'Foundational treatment of algorithms and fundamental programming concepts.'
  UNION ALL SELECT '9780735211292','Atomic Habits','James Clear','Self Development','Avery',2018,'A practical framework for building good habits and breaking bad ones.'
  UNION ALL SELECT '9780374533557','Thinking, Fast and Slow','Daniel Kahneman','Psychology','Farrar, Straus and Giroux',2011,'An exploration of fast and slow systems of human judgment.'
  UNION ALL SELECT '9780316769488','The Catcher in the Rye','J. D. Salinger','Fiction','Little, Brown and Company',1951,'A coming-of-age novel narrated by Holden Caulfield.'
  UNION ALL SELECT '9780141439600','Great Expectations','Charles Dickens','Classic Literature','Penguin Classics',1861,'A classic coming-of-age novel about ambition, identity and social class.'
  UNION ALL SELECT '9780062073488','And Then There Were None','Agatha Christie','Fiction','William Morrow',1939,'A classic mystery novel involving ten strangers brought together on an isolated island.'
) x
JOIN authors a ON a.name=x.author
JOIN categories c ON c.name=x.category;

-- Give every seeded title five physical copies on the main shelf.
INSERT IGNORE INTO book_copies(book_id,accession_no,barcode,shelf_location)
SELECT b.id,CONCAT('MAIN-',LPAD(b.id,4,'0'),'-',n.n),CONCAT('BC-MAIN-',LPAD(b.id,4,'0'),'-',n.n),'A-01'
FROM books b
JOIN (SELECT 1 n UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5) n ON 1=1
WHERE b.title IN (
'Harry Potter and the Philosopher''s Stone','Nineteen Eighty-Four','Pride and Prejudice','To Kill a Mockingbird','The Great Gatsby','War and Peace','The Alchemist','Sapiens: A Brief History of Humankind','A Brief History of Time','Malgudi Days','Wings of Fire','Clean Code','Refactoring: Improving the Design of Existing Code','Introduction to Algorithms','Programming in ANSI C','The Art of Computer Programming, Vol. 1','Atomic Habits','Thinking, Fast and Slow','The Catcher in the Rye','Great Expectations','And Then There Were None');
