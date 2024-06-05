SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

CREATE TABLE `trade` (
  `id` bigint UNSIGNED NOT NULL,
  `timestamp` datetime(3) NOT NULL COMMENT 'Milliseconds',
  `symbol` varchar(10) NOT NULL,
  `data` json NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=ascii COMMENT='All market trades';

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
