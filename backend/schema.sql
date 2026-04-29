--table drops
DROP TABLE IF EXISTS expense_splits;

DROP TABLE IF EXISTS expenses;

DROP TABLE IF EXISTS budgets;

DROP TABLE IF EXISTS group_members;

DROP TABLE IF EXISTS messages;

DROP TABLE IF EXISTS settlements;

DROP TABLE IF EXISTS groups;

DROP TABLE IF EXISTS users;

--table creations
CREATE TABLE IF NOT EXISTS budgets(
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 group_id INTEGER(11) NOT NULL,
 FOREIGN KEY (group_id) REFERENCES groups(id),
 name          VARCHAR(100) NOT NULL,
 category        VARCHAR(100),
 amount_limit  DECIMAL(10,2),
 start_date             DATE,
 end_date               DATE,
 created_by          INTEGER(11),
 FOREIGN KEY (created_by) REFERENCES users(id),
 created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS expenses
(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  group_id INTEGER(11) NOT NULL,
  FOREIGN KEY (group_id) REFERENCES groups(id),
  user_id INTEGER(11) NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id),
  budget_id INTEGER(11),
  FOREIGN KEY (budget_id) REFERENCES budgets(id),
  amount       DECIMAL(10,2) NOT NULL,
  category     VARCHAR(50),
  description  VARCHAR(255),
  expense_date DATE,
  created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
  personal     TINYINT DEFAULT 0
);

CREATE TABLE IF NOT EXISTS expense_splits (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  expense_id INTEGER(11) NOT NULL,
  FOREIGN KEY (expense_id) REFERENCES expenses(id),
  user_id INTEGER(11) NOT NULL,
  FOREIGN KEY (user_id)    REFERENCES    users(id),
  UNIQUE(expense_id, user_id),
  amount_owed   DECIMAL(10,2) NOT NULL,
  created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS groups(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name          VARCHAR(100) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  owner_id       INTEGER(11) NOT NULL,
  FOREIGN KEY (owner_id) REFERENCES users(id),
  created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS group_members(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  group_id INTEGER(11) NOT NULL,
  FOREIGN KEY (group_id) REFERENCES groups(id),
  user_id INTEGER(11) NOT NULL,
  UNIQUE(group_id, user_id),
  FOREIGN KEY (user_id)  REFERENCES  users(id),
  joined_at   DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS messages(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  group_id INTEGER(11) NOT NULL,
  FOREIGN KEY (group_id) REFERENCES groups(id),
  user_id INTEGER(11) NOT NULL,
  FOREIGN KEY (user_id)  REFERENCES  users(id),
  body        TEXT,
  created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS settlements(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  group_id INTEGER(11) NOT NULL,
  FOREIGN KEY (group_id) REFERENCES groups(id),
  payer_user_id INTEGER(11) NOT NULL,
  FOREIGN KEY (payer_user_id) REFERENCES users(id),
  payee_user_id INTEGER(11) NOT NULL,
  FOREIGN KEY (payee_user_id) REFERENCES users(id),
  amount           DECIMAL(10,2) NOT NULL,
  payment_date     DATE,
  note             VARCHAR(255),
  created_by       INTEGER(11),
  FOREIGN KEY (created_by) REFERENCES users(id),
  created_at       DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS users(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name          VARCHAR(100) NOT NULL,
  email         VARCHAR(255) UNIQUE NOT NULL,
  password_hash VARCHAR(100) NOT NULL,
  created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
);
