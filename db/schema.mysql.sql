-- MySQL/MariaDB schema. myvideofeed_blacklist.term holds title-match terms.

CREATE TABLE IF NOT EXISTS `myvideofeed_blacklist` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `term` varchar(255) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `myvideofeed_channels` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `slug` varchar(25) CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `subscribe` tinyint(1) NOT NULL DEFAULT 1,
  `published` datetime DEFAULT NULL,
  `updated` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `myvideofeed_videos` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `channel_id` int(11) unsigned NOT NULL,
  `slug` varchar(12) CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `content` text DEFAULT NULL,
  `duration` time DEFAULT NULL,
  `published` datetime DEFAULT NULL,
  `updated` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `channel_id` (`channel_id`),
  CONSTRAINT `myvideofeed_videos_ibfk_1` FOREIGN KEY (`channel_id`) REFERENCES `myvideofeed_channels` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
