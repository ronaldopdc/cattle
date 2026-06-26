CREATE TABLE IF NOT EXISTS partners (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type ENUM('owner', 'investor') NOT NULL,
    name VARCHAR(255) NOT NULL,
    nationality VARCHAR(100),
    marital_status VARCHAR(50),
    profession VARCHAR(100),
    cpf VARCHAR(14) NOT NULL,
    identity VARCHAR(20),
    address VARCHAR(255),
    city VARCHAR(100),
    state VARCHAR(2),
    zip VARCHAR(10),
    zip VARCHAR(10),
    bank_code VARCHAR(10),
    agency VARCHAR(10),
    account_number VARCHAR(20),
    pix_type ENUM('cpf', 'phone', 'email', 'random'),
    pix VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS lots (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category VARCHAR(100),
    breed VARCHAR(100),
    lot_number VARCHAR(50) NOT NULL,
    protocol_date DATE,
    protocol_weight DECIMAL(10, 2),
    animal_count INT,
    indexed_price DECIMAL(10, 2),
    exit_forecast_date DATE,
    max_advance_percent DECIMAL(5, 2),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS partnerships (
    id INT AUTO_INCREMENT PRIMARY KEY,
    owner_id INT NOT NULL,
    investor_id INT NOT NULL,
    start_date DATE,
    total_value DECIMAL(15, 2),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (owner_id) REFERENCES partners(id),
    FOREIGN KEY (investor_id) REFERENCES partners(id)
);

CREATE TABLE IF NOT EXISTS partnership_lots (
    id INT AUTO_INCREMENT PRIMARY KEY,
    partnership_id INT NOT NULL,
    lot_id INT NOT NULL,
    monthly_rate DECIMAL(5, 2),
    slaughter_date DATE,
    projected_value DECIMAL(15, 2),
    FOREIGN KEY (partnership_id) REFERENCES partnerships(id),
    FOREIGN KEY (lot_id) REFERENCES lots(id)
);

CREATE TABLE IF NOT EXISTS contracts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    template_text TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
