
-- الغرض: جدول بسيط لحفظ توكنات FCM لكل مستخدم (متعدد أجهزة)
CREATE TABLE IF NOT EXISTS users_fcm_tokens (
  fcm_id INT AUTO_INCREMENT PRIMARY KEY,
  fcm_user_id INT NOT NULL,
  fcm_token TEXT NOT NULL,
  fcm_device_type VARCHAR(20) NULL,
  fcm_created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user (fcm_user_id),
  CONSTRAINT fk_fcm_user FOREIGN KEY (fcm_user_id) REFERENCES users(users_id) ON DELETE CASCADE
);