===============================================
4- جدول توكين المستخدمين   - users_tokens
===============================================
CREATE TABLE IF NOT EXISTS users_tokens (
    tokens_id INT AUTO_INCREMENT PRIMARY KEY,
    tokens_users_id INT NOT NULL,
    tokens_value VARCHAR(255) NOT NULL,
    tokens_device_type VARCHAR(50) NULL, -- (android, ios, web)
    tokens_created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tokens_users_id) REFERENCES users(users_id) ON DELETE CASCADE
) ENGINE=InnoDB;

