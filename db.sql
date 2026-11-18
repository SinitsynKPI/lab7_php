CREATE DATABASE IF NOT EXISTS music_catalog CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE music_catalog;

CREATE TABLE IF NOT EXISTS tracks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    artist VARCHAR(255) NOT NULL,
    duration INT NOT NULL 
);

INSERT INTO tracks (title, artist, duration) VALUES
('Song One', 'Artist A', 210),
('Song Two', 'Artist A', 180),
('Song Three', 'Artist B', 240),
('Song Four', 'Artist C', 300),
('Song Five', 'Artist B', 200);