-- Create invitations table if it doesn't exist
CREATE TABLE IF NOT EXISTS wp_charterhub_invitations (
    id bigint(20) NOT NULL AUTO_INCREMENT,
    token varchar(255) NOT NULL,
    email varchar(255) NOT NULL,
    booking_id bigint(20) DEFAULT NULL,
    used boolean DEFAULT FALSE,
    used_at datetime DEFAULT NULL,
    created_by bigint(20) NOT NULL,
    created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at datetime NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY token (token),
    KEY email (email),
    KEY booking_id (booking_id),
    KEY created_by (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4; 