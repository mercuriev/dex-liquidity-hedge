SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

CREATE TABLE `deal`
(
    `id`        varchar(30)                                                       NOT NULL,
    `side`      enum ('bull','bear') CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
    `timestamp` timestamp                                                         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `status`    varchar(16) CHARACTER SET ascii COLLATE ascii_general_ci          NOT NULL DEFAULT 'NEW',
    `amount`    decimal(8, 2) UNSIGNED                                            NOT NULL,
    `outcome`   decimal(5, 2)                                                              DEFAULT NULL,
    `orderIn`   text                                                              NOT NULL,
    `orderOut`  text
) ENGINE = MyISAM
  DEFAULT CHARSET = ascii;

CREATE TABLE `trade` (
  `id` bigint UNSIGNED NOT NULL,
  `timestamp` datetime(3) NOT NULL COMMENT 'Milliseconds',
  `symbol` varchar(10) NOT NULL,
  `data` json NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=ascii COMMENT='All market trades';

ALTER TABLE `deal`
    ADD PRIMARY KEY (`id`),
    ADD KEY `timestamp_2` (`timestamp`, `status`(1)),
    ADD KEY `side` (`side`);

ALTER TABLE `trade`
    ADD PRIMARY KEY (`id`),
    ADD KEY `symbol` (`symbol`) USING BTREE,
    ADD KEY `timestamp` (`timestamp`) USING BTREE;
COMMIT;

CREATE VIEW TradesLastHour AS
    SELECT ROUND(`data`->"$.p", 2) AS price, ROUND(`data`->"$.q", 5) AS quantity, `data`->"$.T" AS timestamp
    FROM trade
    WHERE timestamp > NOW() - INTERVAL 1 HOUR
    ORDER BY timestamp;

CREATE VIEW Yesterday24Hours AS
SELECT ROUND(`data`->"$.p", 2) AS price, ROUND(`data`->"$.q", 5) AS quantity, `data`->"$.T" AS timestamp
FROM trade
WHERE DATE(timestamp) = DATE(NOW() - INTERVAL 1 DAY)
ORDER BY timestamp;
